<?php

namespace App\Http;

/**
 * The validated sort, filter and pagination parameters of a collection request.
 */
final class ListQuery
{
    /**
     * @param list<array{0: string, 1: bool}>                                 $sort    field name and whether it is descending
     * @param array<string, string|int|bool>                                  $filters values keyed by filter name
     */
    public function __construct(
        public readonly int $page,
        public readonly int $size,
        public readonly array $sort,
        public readonly array $filters,
    ) {
    }

    public function offset(): int
    {
        return ($this->page - 1) * $this->size;
    }
}
