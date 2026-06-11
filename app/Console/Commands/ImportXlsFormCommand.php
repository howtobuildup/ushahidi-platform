<?php

namespace App\Console\Commands;

use App\Bus\Command\CommandBus;
use App\Support\XlsFormReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;
use Ushahidi\Core\Entity\Form as SurveyEntity;
use Ushahidi\Modules\V5\Actions\Survey\Commands\CreateSurveyCommand;
use Ushahidi\Modules\V5\Actions\Survey\Commands\CreateSurveyRoleCommand;
use Ushahidi\Modules\V5\Models\Role;
use Ushahidi\Modules\V5\Models\Survey;

class ImportXlsFormCommand extends Command
{
    protected $signature = 'saferworld:import-xlsform
        {file : Path to an XLSForm workbook}
        {--name= : Survey name; defaults to the workbook filename}
        {--roles=admin,field_monitor : Comma-separated roles allowed to submit}
        {--color=4E4B8F : Survey color without #}
        {--dry-run : Parse and report without writing the survey}';

    protected $description = 'Create an Ushahidi survey from a Kobo XLSForm workbook';

    public function handle(CommandBus $commandBus, XlsFormReader $reader)
    {
        $file = $this->resolvePath((string) $this->argument('file'));
        if (!is_readable($file)) {
            $this->error('The XLSForm file does not exist or is not readable.');
            return 1;
        }

        try {
            $workbook = $reader->read($file);
            $surveyRows = $workbook['survey'] ?? [];
            $choiceRows = $workbook['choices'] ?? [];
            $settingsRows = $workbook['settings'] ?? [];
            if (empty($surveyRows)) {
                $this->error('The workbook does not contain a populated survey sheet.');
                return 1;
            }

            $name = trim((string) $this->option('name'));
            if ($name === '') {
                $name = trim(preg_replace('/[_-]+/', ' ', pathinfo($file, PATHINFO_FILENAME)));
            }
            if (Survey::where('name', $name)->exists()) {
                $this->error("A survey named '{$name}' already exists.");
                return 1;
            }

            $choices = $this->buildChoices($choiceRows);
            $fields = $this->buildFields($surveyRows, $choices);
            if (empty($fields)) {
                $this->error('No supported questions were found in the survey sheet.');
                return 1;
            }

            $language = $this->defaultLanguage($settingsRows);
            $roles = $this->resolveRoles((string) $this->option('roles'));
            if ($roles === null) {
                return 1;
            }

            $this->info("Survey: {$name}");
            $this->line('Questions: ' . count($fields));
            $this->line('Default language: ' . $language);
            $this->line('Submission roles: ' . $roles->pluck('name')->implode(', '));

            if ($this->option('dry-run')) {
                $this->info('Dry run complete. No survey was written.');
                return 0;
            }

            DB::beginTransaction();
            try {
                $surveyId = $commandBus->handle(new CreateSurveyCommand(
                    SurveyEntity::buildEntity([
                        'name' => $name,
                        'description' => 'Imported from Kobo XLSForm',
                        'color' => (string) $this->option('color'),
                        'base_language' => $language,
                        'type' => 'report',
                        'disabled' => false,
                        'require_approval' => false,
                        'everyone_can_create' => false,
                        'hide_author' => false,
                        'hide_time' => false,
                        'hide_location' => false,
                    ]),
                    [[
                        'priority' => 0,
                        'required' => false,
                        'type' => 'post',
                        'label' => 'Post',
                        'show_when_published' => true,
                        'task_is_internal_only' => false,
                        'fields' => $fields,
                    ]],
                    []
                ));

                $commandBus->handle(new CreateSurveyRoleCommand(
                    $surveyId,
                    $roles->pluck('id')->map(function ($id) {
                        return (int) $id;
                    })->all()
                ));
                DB::commit();
            } catch (Throwable $error) {
                DB::rollBack();
                throw $error;
            }

            $this->info("Survey imported successfully with ID {$surveyId}.");
            return 0;
        } catch (Throwable $error) {
            $this->error($error->getMessage());
            return 1;
        }
    }

    private function buildChoices(array $rows)
    {
        $choices = [];
        foreach ($rows as $row) {
            $listName = trim((string) ($row['list_name'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            if ($listName === '' || $name === '') {
                continue;
            }
            $label = $this->englishValue($row, 'label') ?: $name;
            $somali = $this->somaliValue($row, 'label') ?: $label;
            $choice = [
                'name' => $name,
                'label' => $label,
                'translations' => [
                    'so' => ['label' => $somali],
                ],
            ];

            foreach ($row as $key => $value) {
                if (
                    in_array($key, [
                        'list_name',
                        'name',
                        'label',
                        'label::English (en)',
                        'label::Somali (so)',
                    ], true)
                ) {
                    continue;
                }

                $value = trim((string) $value);
                if ($value !== '') {
                    $choice[$key] = $value;
                }
            }

            $choices[$listName][] = $choice;
        }
        return $choices;
    }

    private function buildFields(array $rows, array $choices)
    {
        $fields = [];
        $groups = [];
        $priority = 1;

        foreach ($rows as $row) {
            $type = trim((string) ($row['type'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));
            $parts = preg_split('/\s+/', $type);
            $baseType = $parts[0] ?? '';
            $listName = $parts[1] ?? '';

            if ($baseType === 'begin_group') {
                $groups[] = trim((string) ($row['relevant'] ?? ''));
                continue;
            }
            if ($baseType === 'end_group') {
                array_pop($groups);
                continue;
            }
            if ($name === '' || in_array($baseType, [
                '',
                'start',
                'end',
                'today',
                'deviceid',
                'username',
                'calculate',
                'note',
                'audit',
            ], true)) {
                continue;
            }

            $mapping = $this->fieldType($baseType);
            if ($mapping === null) {
                $this->warn("Skipping unsupported XLSForm type '{$baseType}' for '{$name}'.");
                continue;
            }

            $relevant = array_filter(array_merge(
                $groups,
                [trim((string) ($row['relevant'] ?? ''))]
            ));
            $config = [];
            if (!empty($relevant)) {
                $config['relevant'] = implode(' and ', array_map(function ($expression) {
                    return '(' . $expression . ')';
                }, array_unique($relevant)));
            }
            foreach (['constraint', 'constraint_message', 'parameters', 'choice_filter'] as $key) {
                $value = trim((string) ($row[$key] ?? ''));
                if ($value !== '') {
                    $config[$key] = $value;
                }
            }

            $label = $this->englishValue($row, 'label') ?: $name;
            $somaliLabel = $this->somaliValue($row, 'label') ?: $label;
            $options = $choices[$listName] ?? [];
            $somaliOptions = [];
            foreach ($options as $option) {
                $somaliOptions[$option['name']] = $option['translations']['so']['label'];
            }

            $fields[] = [
                'key' => Str::slug($name, '_'),
                'label' => $label,
                'instructions' => $this->englishValue($row, 'hint'),
                'input' => $mapping['input'],
                'type' => $mapping['type'],
                'required' => strtolower(trim((string) ($row['required'] ?? ''))) === 'yes',
                'default' => trim((string) ($row['default'] ?? '')),
                'priority' => $priority++,
                'options' => $options,
                'cardinality' => $baseType === 'select_multiple' ? 0 : 1,
                'config' => $config,
                'response_private' => false,
                'translations' => [
                    'so' => [
                        'label' => $somaliLabel,
                        'options' => $somaliOptions,
                    ],
                ],
            ];
        }

        return $fields;
    }

    private function fieldType($type)
    {
        $types = [
            'text' => ['input' => 'text', 'type' => 'varchar'],
            'integer' => ['input' => 'number', 'type' => 'int'],
            'decimal' => ['input' => 'number', 'type' => 'decimal'],
            'date' => ['input' => 'date', 'type' => 'datetime'],
            'datetime' => ['input' => 'datetime', 'type' => 'datetime'],
            'geopoint' => ['input' => 'location', 'type' => 'point'],
            'select_one' => ['input' => 'select', 'type' => 'varchar'],
            'select_multiple' => ['input' => 'checkbox', 'type' => 'varchar'],
            'image' => ['input' => 'upload', 'type' => 'media'],
            'audio' => ['input' => 'upload', 'type' => 'media'],
            'video' => ['input' => 'upload', 'type' => 'media'],
            'file' => ['input' => 'upload', 'type' => 'media'],
        ];
        return $types[$type] ?? null;
    }

    private function resolveRoles($option)
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $option))));
        $roles = Role::whereIn('name', $names)->get();
        $missing = array_diff($names, $roles->pluck('name')->all());
        if (!empty($missing)) {
            $this->error('Unknown roles: ' . implode(', ', $missing));
            return null;
        }
        return $roles;
    }

    private function defaultLanguage(array $settingsRows)
    {
        $language = trim((string) ($settingsRows[0]['default_language'] ?? 'en'));
        if (preg_match('/\(([^)]+)\)/', $language, $matches)) {
            return $matches[1];
        }
        return $language ?: 'en';
    }

    private function englishValue(array $row, $prefix)
    {
        return $this->localizedValue($row, $prefix, ['english', ' en']);
    }

    private function somaliValue(array $row, $prefix)
    {
        return $this->localizedValue($row, $prefix, ['somali', ' so']);
    }

    private function localizedValue(array $row, $prefix, array $languages)
    {
        foreach ($row as $key => $value) {
            $normalized = strtolower((string) $key);
            if (strpos($normalized, strtolower($prefix)) !== 0) {
                continue;
            }
            foreach ($languages as $language) {
                if (strpos($normalized, $language) !== false) {
                    return trim((string) $value);
                }
            }
        }
        return trim((string) ($row[$prefix] ?? ''));
    }

    private function resolvePath($path)
    {
        return isset($path[0]) && $path[0] === DIRECTORY_SEPARATOR ? $path : base_path($path);
    }
}
