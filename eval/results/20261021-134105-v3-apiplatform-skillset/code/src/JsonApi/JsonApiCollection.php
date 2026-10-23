<?php

namespace App\JsonApi;

/**
 * One page of a collection, with the total number of matching items.
 */
final readonly class JsonApiCollection
{
    /**
     * @param list<JsonApiResourceInterface> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $number,
        public int $size,
    ) {
    }
}
