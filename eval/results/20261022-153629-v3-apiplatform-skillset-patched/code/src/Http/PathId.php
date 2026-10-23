<?php

namespace App\Http;

/**
 * Reads the {id} of an item path. A malformed id is not a resource, so it answers 404 like an unknown one.
 */
final class PathId
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    public static function from(array $uriVariables): ?int
    {
        $id = (string) ($uriVariables['id'] ?? '');

        return 1 === preg_match('/^\d{1,18}$/', $id) ? (int) $id : null;
    }
}
