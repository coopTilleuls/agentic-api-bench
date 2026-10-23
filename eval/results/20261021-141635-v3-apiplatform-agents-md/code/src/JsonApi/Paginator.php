<?php

namespace App\JsonApi;

use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * One page of a collection, with the total number of items across all pages.
 *
 * @implements PaginatorInterface<object>
 */
final class Paginator implements PaginatorInterface, \IteratorAggregate
{
    /**
     * @param list<object> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $totalItems,
        private readonly int $currentPage,
        private readonly int $itemsPerPage,
    ) {
    }

    public function getLastPage(): float
    {
        return max(1, (int) ceil($this->totalItems / $this->itemsPerPage));
    }

    public function getTotalItems(): float
    {
        return $this->totalItems;
    }

    public function getCurrentPage(): float
    {
        return $this->currentPage;
    }

    public function getItemsPerPage(): float
    {
        return $this->itemsPerPage;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }
}
