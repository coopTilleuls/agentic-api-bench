<?php

namespace App\Pagination;

use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * One page of a collection, with the totals needed for the JSON:API links and meta.
 *
 * @implements \IteratorAggregate<int, object>
 */
final class Page implements PaginatorInterface, \IteratorAggregate
{
    /**
     * @param list<object> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $currentPage,
        private readonly int $itemsPerPage,
        private readonly int $totalItems,
    ) {
    }

    public function getLastPage(): float
    {
        return (float) max(1, (int) ceil($this->totalItems / $this->itemsPerPage));
    }

    public function getTotalItems(): float
    {
        return (float) $this->totalItems;
    }

    public function getCurrentPage(): float
    {
        return (float) $this->currentPage;
    }

    public function getItemsPerPage(): float
    {
        return (float) $this->itemsPerPage;
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
