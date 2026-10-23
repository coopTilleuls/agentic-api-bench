<?php

namespace App\Http;

/**
 * Database ids in paths. Anything that is not a plain integer is an unknown resource.
 */
final class PathId
{
    public static function parse(string $raw): ?int
    {
        return 1 === preg_match('/^\d{1,18}$/', $raw) ? (int) $raw : null;
    }
}
