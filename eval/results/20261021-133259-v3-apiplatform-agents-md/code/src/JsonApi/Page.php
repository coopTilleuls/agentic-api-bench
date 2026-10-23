<?php

namespace App\JsonApi;

/**
 * The page requested with page[number] and page[size].
 */
final class Page
{
    public function __construct(
        public readonly int $number,
        public readonly int $size,
    ) {
    }

    public function offset(): int
    {
        return ($this->number - 1) * $this->size;
    }
}
