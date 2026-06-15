<?php

namespace Ushahidi\Core\Tool\FileReader;

class CSVHeaders
{
    public static function makeUnique(array $headers)
    {
        $counts = [];

        return array_map(function ($header) use (&$counts) {
            $header = trim((string) $header);
            $base = $header !== '' ? $header : 'Unnamed column';
            $counts[$base] = ($counts[$base] ?? 0) + 1;

            return $counts[$base] === 1
                ? $base
                : sprintf('%s (%d)', $base, $counts[$base]);
        }, $headers);
    }
}
