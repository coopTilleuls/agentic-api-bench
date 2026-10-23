<?php

namespace App\JsonApi;

use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * A page of a collection, with the total number of items across all pages.
 */
final class Paginator implements PaginatorInterface, \IteratorAggregate
{
    /**
     * @param list<object> $items the items of the current page
     */
    public function __construct(
        private readonly array $items,
        private readonly int $totalItems,
        private readonly int $page,
        private readonly int $size,
    ) {
    }

    public function getLastPage(): float
    {
        return (float) max(1, (int) ceil($this->totalItems / $this->size));
    }

    public function getTotalItems(): float
    {
        return (float) $this->totalItems;
    }

    public function getCurrentPage(): float
    {
        return (float) $this->page;
    }

    public function getItemsPerPage(): float
    {
        return (float) $this->size;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }
}
