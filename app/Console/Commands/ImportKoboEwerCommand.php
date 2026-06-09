<?php

namespace App\Console\Commands;

use App\Bus\Command\CommandBus;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use SplFileObject;
use Throwable;
use Ushahidi\Core\Entity\Post as PostEntity;
use Ushahidi\Modules\V5\Actions\Post\Commands\CreatePostCommand;
use Ushahidi\Modules\V5\Models\Attribute;
use Ushahidi\Modules\V5\Models\Post\Post;
use Ushahidi\Modules\V5\Models\Post\PostStatus;
use Ushahidi\Modules\V5\Models\Stage;
use Ushahidi\Modules\V5\Models\Survey;
use Ushahidi\Modules\V5\Models\User;

class ImportKoboEwerCommand extends Command
{
    protected $signature = 'saferworld:import-kobo
        {file : Path to a Kobo CSV export}
        {--form-id= : ID of the destination survey}
        {--user-id= : Default owner user ID}
        {--user-map= : JSON file mapping Field Monitor Code values to user IDs}
        {--mapping= : JSON file mapping CSV headers to survey field keys}
        {--status=published : Post status for imported submissions}
        {--delimiter= : CSV delimiter; detected automatically when omitted}
        {--limit=0 : Maximum number of data rows to process}
        {--dry-run : Validate and report without writing posts}
        {--allow-missing-uuid : Import rows that do not contain a Kobo UUID}
        {--stop-on-error : Stop when the first row fails}';

    protected $description = 'Import historical Kobo EWER submissions as Ushahidi posts';

    private $specialHeaders = [
        '_id',
        '_uuid',
        '_submission_time',
        '_validation_status',
        '_notes',
        '_status',
        '_submitted_by',
        '__version__',
        '_tags',
        '_index',
        'meta/rootUuid',
    ];

    public function handle(CommandBus $commandBus)
    {
        $file = $this->resolveFilePath((string) $this->argument('file'));
        if (!$file || !is_readable($file)) {
            $this->error('The CSV file does not exist or is not readable.');
            return 1;
        }

        $formId = (int) $this->option('form-id');
        $survey = Survey::find($formId);
        if (!$survey) {
            $this->error('A valid --form-id is required.');
            return 1;
        }

        $defaultUserId = $this->nullableInt($this->option('user-id'));
        if ($defaultUserId && !User::where('id', $defaultUserId)->exists()) {
            $this->error("Default user {$defaultUserId} does not exist.");
            return 1;
        }

        $userMap = $this->loadJsonMap($this->option('user-map'), 'user map');
        if ($userMap === null) {
            return 1;
        }
        if (!$this->validateUserMap($userMap)) {
            return 1;
        }

        if (!$defaultUserId && empty($userMap)) {
            $this->error('Provide --user-id, --user-map, or both so every post has a valid owner.');
            return 1;
        }

        $mappingConfig = $this->loadJsonMap($this->option('mapping'), 'field mapping');
        if ($mappingConfig === null) {
            return 1;
        }
        $mappingOverrides = isset($mappingConfig['fields']) && is_array($mappingConfig['fields'])
            ? $mappingConfig['fields']
            : $mappingConfig;

        $stages = Stage::where('form_id', $formId)
            ->with('fields')
            ->orderBy('priority')
            ->get();

        $attributes = $stages->flatMap(function (Stage $stage) {
            return $stage->fields;
        });

        if ($attributes->isEmpty()) {
            $this->error("Survey {$formId} has no fields.");
            return 1;
        }

        $delimiter = $this->resolveDelimiter($file, $this->option('delimiter'));
        $csv = new SplFileObject($file, 'r');
        $csv->setCsvControl($delimiter, '"', '\\');

        $headers = $csv->fgetcsv();
        if (!is_array($headers) || count($headers) === 0) {
            $this->error('The CSV file has no header row.');
            return 1;
        }
        $headers = $this->makeUniqueHeaders(array_map([$this, 'cleanHeader'], $headers));

        $fieldMap = $this->buildFieldMap($headers, $attributes, $mappingOverrides);
        if (empty($fieldMap)) {
            $this->error(
                'No CSV headers matched this survey. Import the matching XLSForm first or provide --mapping.'
            );
            return 1;
        }

        $mappedAttributeIds = array_map(function (Attribute $attribute) {
            return (int) $attribute->id;
        }, array_values($fieldMap));

        $unmappedRequired = $attributes->filter(function (Attribute $attribute) use ($mappedAttributeIds) {
            return (bool) $attribute->required
                && !in_array((int) $attribute->id, $mappedAttributeIds, true)
                && !in_array($attribute->type, ['title', 'description'], true);
        });

        $unmappedHeaders = array_values(array_filter($headers, function ($header) use ($fieldMap) {
            $baseHeader = $this->baseHeader($header);
            return $baseHeader !== ''
                && !array_key_exists($header, $fieldMap)
                && !$this->isSpecialHeader($baseHeader)
                && strpos($baseHeader, '/') === false;
        }));

        $this->printMappingSummary(
            $survey,
            $delimiter,
            $fieldMap,
            $unmappedHeaders,
            $unmappedRequired->all()
        );

        $existingUuids = $this->loadExistingKoboUuids($formId);
        $dryRun = (bool) $this->option('dry-run');
        $allowMissingUuid = (bool) $this->option('allow-missing-uuid');
        $stopOnError = (bool) $this->option('stop-on-error');
        $limit = max(0, (int) $this->option('limit'));
        $status = (string) $this->option('status');
        if (!in_array($status, PostStatus::all(), true)) {
            $this->error('Invalid --status. Allowed values: ' . implode(', ', PostStatus::all()));
            return 1;
        }

        $stats = [
            'read' => 0,
            'ready' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        while (!$csv->eof()) {
            $values = $csv->fgetcsv();
            if (!is_array($values) || $this->isEmptyRow($values)) {
                continue;
            }
            if ($limit > 0 && $stats['read'] >= $limit) {
                break;
            }

            $stats['read']++;
            $rowNumber = $stats['read'] + 1;
            $row = $this->combineRow($headers, $values);
            $uuid = trim((string) ($row['_uuid'] ?? ''));

            if ($uuid === '' && !$allowMissingUuid) {
                $this->warn("Row {$rowNumber}: skipped because _uuid is empty.");
                $stats['skipped']++;
                continue;
            }
            if ($uuid !== '' && isset($existingUuids[$uuid])) {
                $stats['duplicates']++;
                continue;
            }

            $monitorCode = trim((string) $this->rowValue(
                $row,
                ['Field Monitor Code', 'field_monitor_code']
            ));
            $userId = $this->resolveUserId($monitorCode, $userMap, $defaultUserId);
            if (!$userId) {
                $this->warn("Row {$rowNumber}: no user mapped for Field Monitor Code '{$monitorCode}'.");
                $stats['skipped']++;
                continue;
            }

            try {
                $postContent = $this->buildPostContent($row, $stages, $fieldMap, $headers);
                $submissionTime = $this->parseDate($row['_submission_time'] ?? null);
                $title = $this->buildTitle($row, $fieldMap);
                $stats['ready']++;

                if ($dryRun) {
                    continue;
                }

                $postId = $commandBus->handle(new CreatePostCommand(
                    new PostEntity([
                        'form_id' => $formId,
                        'user_id' => $userId,
                        'type' => 'report',
                        'title' => $title,
                        'content' => null,
                        'status' => $status,
                        'created' => $submissionTime->getTimestamp(),
                        'post_date' => $submissionTime->format('Y-m-d H:i:s'),
                        'locale' => 'en_US',
                        'base_language' => 'en',
                        'published_to' => [],
                        'source' => 'web',
                        'metadata' => [
                            'import' => [
                                'source' => 'kobo',
                                'uuid' => $uuid ?: null,
                                'submission_id' => $row['_id'] ?? null,
                                'submitted_by' => $row['_submitted_by'] ?? null,
                                'imported_at' => gmdate('c'),
                            ],
                            'field_monitor_code' => $monitorCode ?: null,
                        ],
                    ]),
                    array_column($postContent, 'id'),
                    $postContent,
                    []
                ));

                $stats['imported']++;
                if ($uuid !== '') {
                    $existingUuids[$uuid] = $postId;
                }
            } catch (Throwable $error) {
                $stats['failed']++;
                $this->error("Row {$rowNumber}: {$error->getMessage()}");
                if ($stopOnError) {
                    break;
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Read', 'Ready', 'Imported', 'Duplicates', 'Skipped', 'Failed'],
            [[
                $stats['read'],
                $stats['ready'],
                $stats['imported'],
                $stats['duplicates'],
                $stats['skipped'],
                $stats['failed'],
            ]]
        );

        if ($dryRun) {
            $this->info('Dry run complete. No posts were written.');
        }

        return $stats['failed'] > 0 ? 1 : 0;
    }

    private function buildFieldMap(array $headers, $attributes, array $overrides)
    {
        $aliases = [
            'State where incidence occurred' => 'Region where incidence occurred',
            'City where incidence occurred' => 'District where incidence occurred',
            'When did the observed incidence/event start?' => 'When did the observed indicator/event start?',
        ];
        $attributesByName = [];
        foreach ($attributes as $attribute) {
            foreach ([$attribute->key, $attribute->label] as $candidate) {
                $normalized = $this->normalizeName($candidate);
                if ($normalized !== '') {
                    if (!isset($attributesByName[$normalized])) {
                        $attributesByName[$normalized] = [];
                    }
                    if (!in_array($attribute, $attributesByName[$normalized], true)) {
                        $attributesByName[$normalized][] = $attribute;
                    }
                }
            }
        }

        $map = [];
        $positions = [];
        foreach ($headers as $header) {
            $baseHeader = $this->baseHeader($header);
            $target = $overrides[$header]
                ?? $overrides[$baseHeader]
                ?? $aliases[$baseHeader]
                ?? $baseHeader;
            $normalized = $this->normalizeName($target);
            if (!empty($attributesByName[$normalized])) {
                $position = $positions[$normalized] ?? 0;
                $attribute = $attributesByName[$normalized][$position]
                    ?? end($attributesByName[$normalized]);
                $map[$header] = $attribute;
                $positions[$normalized] = $position + 1;
            }
        }

        return $map;
    }

    private function buildPostContent(array $row, $stages, array $fieldMap, array $headers)
    {
        $content = [];
        foreach ($stages as $stage) {
            $fields = [];
            foreach ($stage->fields as $attribute) {
                if (in_array($attribute->type, ['title', 'description'], true)) {
                    continue;
                }

                $header = array_search($attribute, $fieldMap, true);
                $rawValue = $header !== false ? ($row[$header] ?? null) : null;
                if ($this->isBlank($rawValue) && $this->isProjectField($attribute)) {
                    $rawValue = 'NAGAASHO';
                }

                if ($attribute->input === 'checkbox') {
                    $expandedChoices = $this->collectExpandedChoices($row, $headers, $attribute);
                    if (!$this->isBlank($expandedChoices)) {
                        $rawValue = $expandedChoices;
                    }
                }

                if ($this->isBlank($rawValue)) {
                    continue;
                }

                $value = $this->normalizeValue($attribute, $rawValue);
                if ($this->isBlank($value)) {
                    continue;
                }

                $fields[] = [
                    'id' => (int) $attribute->id,
                    'type' => $attribute->type,
                    'value' => ['value' => $value],
                ];
            }

            if (!empty($fields)) {
                $content[] = [
                    'id' => (int) $stage->id,
                    'fields' => $fields,
                ];
            }
        }

        return $content;
    }

    private function isProjectField(Attribute $attribute)
    {
        return in_array($this->normalizeName($attribute->label), [
            'project name',
            'projec name',
        ], true);
    }

    private function collectExpandedChoices(array $row, array $headers, Attribute $attribute)
    {
        $prefixes = array_filter([
            trim((string) $attribute->label),
            trim((string) $attribute->key),
        ]);
        $selected = [];

        foreach ($headers as $header) {
            $baseHeader = $this->baseHeader($header);
            foreach ($prefixes as $prefix) {
                $needle = $prefix . '/';
                if (stripos($baseHeader, $needle) !== 0) {
                    continue;
                }
                if ($this->isTruthySelection($row[$header] ?? null)) {
                    $selected[] = substr($baseHeader, strlen($needle));
                }
            }
        }

        return $selected;
    }

    private function normalizeValue(Attribute $attribute, $rawValue)
    {
        if ($attribute->input === 'checkbox') {
            $values = is_array($rawValue)
                ? $rawValue
                : preg_split('/\s*[;,]\s*/', trim((string) $rawValue), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique(array_map(function ($value) use ($attribute) {
                return $this->normalizeOption($attribute, $value);
            }, $values)));
        }

        if (in_array($attribute->input, ['select', 'radio'], true)) {
            return $this->normalizeOption($attribute, $rawValue);
        }

        if ($attribute->type === 'int') {
            return (int) preg_replace('/[^\d-]/', '', (string) $rawValue);
        }

        if ($attribute->type === 'decimal') {
            return (float) str_replace(',', '', (string) $rawValue);
        }

        if ($attribute->type === 'datetime') {
            return $this->normalizeDateValue($rawValue, $attribute->input === 'date');
        }

        if ($attribute->type === 'point') {
            return $this->normalizePoint($rawValue);
        }

        return trim((string) $rawValue);
    }

    private function normalizeOption(Attribute $attribute, $rawValue)
    {
        $value = trim((string) $rawValue);
        $normalizedValue = $this->normalizeName($value);

        foreach ((array) $attribute->options as $option) {
            $option = is_object($option) ? (array) $option : $option;
            if (!is_array($option)) {
                if ($this->normalizeName($option) === $normalizedValue) {
                    return (string) $option;
                }
                continue;
            }

            $name = $option['name'] ?? $option['value'] ?? $option['id'] ?? null;
            $label = $option['label'] ?? null;
            foreach ([$name, $label] as $candidate) {
                if ($candidate !== null && $this->normalizeName($candidate) === $normalizedValue) {
                    return (string) ($name ?? $label);
                }
            }
        }

        return $value;
    }

    private function normalizePoint($rawValue)
    {
        if (is_array($rawValue) && isset($rawValue['lat'], $rawValue['lon'])) {
            return ['lat' => (float) $rawValue['lat'], 'lon' => (float) $rawValue['lon']];
        }

        $parts = preg_split('/[\s,]+/', trim((string) $rawValue));
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            throw new \InvalidArgumentException("Invalid point value '{$rawValue}'.");
        }

        return ['lat' => (float) $parts[0], 'lon' => (float) $parts[1]];
    }

    private function normalizeDateValue($value, $dateOnly)
    {
        $date = $this->parseDate($value);
        return $dateOnly ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
    }

    private function parseDate($value)
    {
        $timezone = new DateTimeZone('UTC');
        if ($value === null || trim((string) $value) === '') {
            return new DateTimeImmutable('now', $timezone);
        }

        try {
            return new DateTimeImmutable((string) $value, $timezone);
        } catch (Throwable $error) {
            throw new \InvalidArgumentException("Invalid date '{$value}'.");
        }
    }

    private function buildTitle(array $row, array $fieldMap)
    {
        $parts = [];
        foreach ($fieldMap as $header => $attribute) {
            $name = $this->normalizeName($attribute->key . ' ' . $attribute->label);
            if (strpos($name, 'incidence type') !== false || strpos($name, 'incident type') !== false) {
                $parts[] = trim((string) ($row[$header] ?? ''));
            }
            if (strpos($name, 'district') !== false) {
                $parts[] = trim((string) ($row[$header] ?? ''));
            }
        }

        $parts = array_values(array_unique(array_filter($parts)));
        $date = $this->parseDate($row['_submission_time'] ?? null)->format('Y-m-d');
        return trim('Kobo ' . implode(' - ', $parts) . ' - ' . $date, ' -');
    }

    private function loadExistingKoboUuids($formId)
    {
        $uuids = [];
        Post::where('form_id', $formId)
            ->whereNotNull('metadata')
            ->select(['id', 'metadata'])
            ->chunk(500, function ($posts) use (&$uuids) {
                foreach ($posts as $post) {
                    $metadata = $post->metadata;
                    $uuid = is_array($metadata)
                        ? ($metadata['import']['uuid'] ?? null)
                        : null;
                    if ($uuid) {
                        $uuids[$uuid] = (int) $post->id;
                    }
                }
            });

        return $uuids;
    }

    private function resolveUserId($monitorCode, array $userMap, $defaultUserId)
    {
        if ($monitorCode !== '' && isset($userMap[$monitorCode])) {
            return (int) $userMap[$monitorCode];
        }
        return $defaultUserId;
    }

    private function validateUserMap(array $userMap)
    {
        $ids = array_values(array_unique(array_map('intval', $userMap)));
        if (empty($ids)) {
            return true;
        }

        $existing = User::whereIn('id', $ids)->pluck('id')->map(function ($id) {
            return (int) $id;
        })->all();
        $missing = array_values(array_diff($ids, $existing));

        if (!empty($missing)) {
            $this->error('The user map references missing user IDs: ' . implode(', ', $missing));
            return false;
        }

        return true;
    }

    private function loadJsonMap($path, $label)
    {
        if (!$path) {
            return [];
        }

        $path = $this->resolveFilePath((string) $path);
        if (!$path || !is_readable($path)) {
            $this->error("The {$label} file does not exist or is not readable.");
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            $this->error("The {$label} file must contain a JSON object.");
            return null;
        }

        return $data;
    }

    private function resolveFilePath($path)
    {
        if ($path === '') {
            return null;
        }
        if ($path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }
        return base_path($path);
    }

    private function resolveDelimiter($file, $requested)
    {
        if ($requested !== null && $requested !== '') {
            return $requested === '\t' ? "\t" : substr((string) $requested, 0, 1);
        }

        $handle = fopen($file, 'r');
        $line = $handle ? fgets($handle) : '';
        if ($handle) {
            fclose($handle);
        }

        $counts = [
            ';' => substr_count((string) $line, ';'),
            ',' => substr_count((string) $line, ','),
            "\t" => substr_count((string) $line, "\t"),
        ];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    private function combineRow(array $headers, array $values)
    {
        $values = array_pad(array_slice($values, 0, count($headers)), count($headers), null);
        return array_combine($headers, $values);
    }

    private function rowValue(array $row, array $names)
    {
        $normalizedNames = array_map([$this, 'normalizeName'], $names);
        foreach ($row as $header => $value) {
            if (in_array($this->normalizeName($this->baseHeader($header)), $normalizedNames, true)) {
                return $value;
            }
        }
        return null;
    }

    private function makeUniqueHeaders(array $headers)
    {
        $counts = [];
        return array_map(function ($header) use (&$counts) {
            $counts[$header] = ($counts[$header] ?? 0) + 1;
            return $counts[$header] === 1 ? $header : $header . ' [#' . $counts[$header] . ']';
        }, $headers);
    }

    private function baseHeader($header)
    {
        return preg_replace('/ \[#\d+\]$/', '', (string) $header);
    }

    private function cleanHeader($header)
    {
        return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $header));
    }

    private function normalizeName($value)
    {
        $value = Str::ascii(trim((string) $value));
        $value = strtolower($value);
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $value));
    }

    private function isSpecialHeader($header)
    {
        return in_array($header, $this->specialHeaders, true) || strpos($header, '_') === 0;
    }

    private function isEmptyRow(array $row)
    {
        foreach ($row as $value) {
            if (!$this->isBlank($value)) {
                return false;
            }
        }
        return true;
    }

    private function isBlank($value)
    {
        if (is_array($value)) {
            return count(array_filter($value, function ($item) {
                return trim((string) $item) !== '';
            })) === 0;
        }
        return $value === null || trim((string) $value) === '';
    }

    private function isTruthySelection($value)
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'selected'], true);
    }

    private function nullableInt($value)
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function printMappingSummary($survey, $delimiter, array $fieldMap, array $unmappedHeaders, array $required)
    {
        $this->info("Survey: {$survey->name} (#{$survey->id})");
        $this->line('Delimiter: ' . ($delimiter === "\t" ? '\t' : $delimiter));
        $this->line('Mapped fields: ' . count($fieldMap));

        if (!empty($unmappedHeaders)) {
            $this->warn(
                'Unmapped CSV headers: '
                . implode(', ', array_map([$this, 'baseHeader'], array_slice($unmappedHeaders, 0, 15)))
                . (count($unmappedHeaders) > 15 ? ' ...' : '')
            );
        }

        if (!empty($required)) {
            $this->warn('Required survey fields without a CSV mapping: ' . implode(', ', array_map(
                function (Attribute $attribute) {
                    return $attribute->label;
                },
                $required
            )));
        }
    }
}
