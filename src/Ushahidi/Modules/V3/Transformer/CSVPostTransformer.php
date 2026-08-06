<?php

/**
 * Ushahidi CSV Transformer
 *
 * @author    Ushahidi Team <team@ushahidi.com>
 * @package   Ushahidi\Application
 * @copyright 2014 Ushahidi
 * @license   https://www.gnu.org/licenses/agpl-3.0.html GNU Affero General Public License Version 3 (AGPL3)
 */

namespace Ushahidi\Modules\V3\Transformer;

use Ushahidi\Contracts\MappingTransformer;
use Ushahidi\Contracts\Repository\Entity\PostRepository;

class CSVPostTransformer implements MappingTransformer
{
    protected $columnNames;
    protected $map;
    protected $fixedValues;
    protected $repo;
    protected $unmapped;

    public function setColumnNames(Array $columnNames)
    {
        $this->columnNames = $columnNames;
    }

    public function setRepo(PostRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * This function transforms values in the record read from the CSV,
     * according to specific syntax that's provided in CSV file column names.
     * the syntax is like this: "name->{transformSpec}"
     * i.e.
     *  - given: $record[x] == "1,2,3" and $this->columnNames[x] == "somename->{explodeCommas}"
     *  - explode(",", $record[x]) will be applied to the value in the record and as a result ...
     *  - $record[x] == array("1","2","3")
     *
     * if an unknown transformation specification is provided, the value is unchanged
     */
    public function transformValues(&$record)
    {
        foreach ($this->columnNames as $index => $columnName) {
            if (!array_key_exists($index, $record)) {
                continue;
            }

            $transformMatch = [];
            if (preg_match('/^.+(?=->)->\{(.+)(?=\})\}$/', $columnName, $transformMatch)) {
                $record[$index] = $this->transformValue($transformMatch[1], $record[$index]);
            }
        }
    }

    public function transformValue($transformSpec, $value)
    {
        switch ($transformSpec) {
            case 'explodeCommas':
                return explode(',', $value);
            default:
                return $value;
        }
    }

    // MappingTransformer
    public function setMap(array $map)
    {
        $this->map = $map;
    }

    // MappingTransformer
    public function setFixedValues(array $fixedValues)
    {
        $this->fixedValues = $fixedValues;
    }

    // Transformer
    public function interact(array $record)
    {
        $record = array_values($record);
        $record = $this->normalizeRecordLength($record);

        // Trim values
        foreach ($record as $key => $val) {
            $record[$key] = trim((string) $val);
        }

        $submissionTime = $this->resolveKoboSubmissionTime($record);

        // Transform values according to specs in column names
        $this->transformValues($record);

        $record = $this->remapRecordColumns($record);

        if (empty($record['post_date']) && $submissionTime) {
            $record['post_date'] = $submissionTime;
        }

        // Remove empty values
        foreach ($record as $key => $val) {
            if (empty($record[$key])) {
                unset($record[$key]);
            } elseif (is_array($record[$key])) {
                $record[$key] = array_filter(
                    $record[$key],
                    function ($x) {
                        return !empty($x);
                    }
                );
            }
        }

        // Merge multi-value columns
        $this->mergeMultiValueFields($record);

        // Filter post fields from the record
        $post_entity = $this->repo->getEntity();
        $post_fields = array_intersect_key($record, $post_entity->asArray());

        // Remove post fields from the record and leave form values
        foreach ($post_fields as $key => $val) {
            unset($record[$key]);
        }

        // Put values in array
        array_walk(
            $record,
            function (&$val) {
                if ($this->isLocation($val)) {
                    $val = [$val];
                }

                if (! is_array($val)) {
                    $val = [$val];
                }
            }
        );

        $form_values = ['values' => $record];


        return array_merge_recursive(
            $post_fields,
            $form_values,
            $this->fixedValues
        );
    }

    private function normalizeRecordLength(array $record): array
    {
        $expectedColumnCount = count($this->map ?: $this->columnNames ?: $record);

        return array_pad(
            array_slice($record, 0, $expectedColumnCount),
            $expectedColumnCount,
            null
        );
    }

    private function remapRecordColumns(array $record): array
    {
        $remappedRecord = [];

        foreach ($this->map as $index => $column) {
            if ($column === null || !array_key_exists($index, $record)) {
                continue;
            }

            $value = $record[$index];
            $sourceColumn = $this->columnNames[$index] ?? '';

            if ($this->isKoboGpsColumn($sourceColumn)) {
                $value = $this->resolveKoboGpsValue($record, $index);
                $value = $this->parseKoboGpsValue($value);
            }

            $remappedRecord[$column] = $value;
        }

        return $remappedRecord;
    }

    /**
     * Kobo stores geopoints as "latitude longitude altitude precision".
     * Ushahidi point attributes require an associative lat/lon value.
     */
    private function parseKoboGpsValue($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }

        $parts = preg_split('/[\s,]+/', trim($value));
        if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
            return $value;
        }

        $lat = (float) $parts[0];
        $lon = (float) $parts[1];
        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
            return $value;
        }

        return [
            'lat' => $lat,
            'lon' => $lon,
        ];
    }

    /**
     * "All versions" Kobo exports suffix duplicate GPS columns (gps_001,
     * gps_002, ...). If the mapped GPS column is empty for a row, use the
     * only populated GPS variant. Multiple populated variants are ambiguous
     * and are deliberately not guessed.
     */
    private function resolveKoboGpsValue(array $record, int $mappedIndex)
    {
        $mappedValue = $record[$mappedIndex] ?? null;
        if (is_string($mappedValue) && trim($mappedValue) !== '') {
            return $mappedValue;
        }

        $populatedValues = [];
        foreach ($this->columnNames as $index => $columnName) {
            if (!$this->isKoboGpsColumn($columnName)) {
                continue;
            }

            $value = $record[$index] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $populatedValues[] = $value;
            }
        }

        return count($populatedValues) === 1 ? $populatedValues[0] : $mappedValue;
    }

    private function isKoboGpsColumn($columnName): bool
    {
        return is_string($columnName) && preg_match('/^gps(?:_\d+)?$/i', trim($columnName)) === 1;
    }

    /**
     * Kobo's _submission_time is the original server submission timestamp.
     * Preserve it as the post date so imported reports keep their historical
     * position in timelines instead of appearing on the CSV upload date.
     */
    private function resolveKoboSubmissionTime(array $record): ?\DateTimeImmutable
    {
        foreach ($this->columnNames as $index => $columnName) {
            if (!is_string($columnName) || strtolower(trim($columnName)) !== '_submission_time') {
                continue;
            }

            $value = trim((string) ($record[$index] ?? ''));
            if ($value === '') {
                return null;
            }

            try {
                return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            } catch (\Exception $exception) {
                return null;
            }
        }

        return null;
    }

    /**
     * Multi-value columns use dot notation to add sub-keys
     * e.g. 'location.lat' refers to a field called 'location'
     * and 'lat' is a sub-key of the field.
     *
     * @param array &$record
     */
    private function mergeMultiValueFields(&$record)
    {
        foreach ($record as $column => $val) {
            $keys = explode('.', $column);

            // Get column name
            $column_name = array_shift($keys);

            // Assign sub-key to multi-value column
            if (! empty($keys)) {
                unset($record[$column]);

                foreach ($keys as $key) {
                    $record[$column_name][$key] = $val;
                }
            }
        }
    }

    private function isLocation($value)
    {
        return is_array($value) &&
            array_key_exists('lon', $value) &&
            array_key_exists('lat', $value);
    }
}
