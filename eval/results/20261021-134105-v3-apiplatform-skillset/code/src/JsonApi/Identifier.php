<?php

namespace App\JsonApi;

/**
 * Reads the identifier of a path variable, null when malformed (answered as 404).
 */
final class Identifier
{
    public static function fromPath(mixed $value): ?int
    {
        if (\is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (\is_string($value) && 1 === preg_match('/^\d{1,18}$/', $value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }
}
