<?php

namespace App\Repository;

final class Placeholders
{
    /**
     * "?,?,?" for a list of values.
     *
     * @param list<mixed> $values
     */
    public static function for(array $values): string
    {
        return implode(',', array_fill(0, \count($values), '?'));
    }
}
