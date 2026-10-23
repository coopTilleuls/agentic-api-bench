<?php

namespace App\Api;

use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * A page of already-mapped resources, with the total of all matching items.
 *
 * @implements \IteratorAggregate<int, object>
 */
final class PageResult implements PaginatorInterface, \IteratorAggregate
{
    /**
     * @param list<object> $items
     */
    public function __construct(private readonly array $items, private readonly int $totalItems, private readonly int $page, private readonly int $size)
    {
    }

    public function getCurrentPage(): float
    {
        return $this->page;
    }

    public function getItemsPerPage(): float
    {
        return $this->size;
    }

    public function getLastPage(): float
    {
        return max(1, (int) ceil($this->totalItems / $this->size));
    }

    public function getTotalItems(): float
    {
        return $this->totalItems;
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
