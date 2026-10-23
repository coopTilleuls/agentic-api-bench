<?php

namespace App\Service;

/**
 * RFC 4180 CSV lines, CRLF terminated.
 */
final class Csv
{
    /** @param list<string|int> $fields */
    public static function line(array $fields): string
    {
        $cells = array_map(static function (string|int $field): string {
            $value = (string) $field;
            if (preg_match('/[",\r\n]/', $value)) {
                return '"'.str_replace('"', '""', $value).'"';
            }

            return $value;
        }, $fields);

        return implode(',', $cells)."\r\n";
    }
}
