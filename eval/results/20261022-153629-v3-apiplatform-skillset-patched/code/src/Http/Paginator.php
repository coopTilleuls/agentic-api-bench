<?php

namespace App\Http;

use ApiPlatform\State\Pagination\PaginatorInterface;

/**
 * A page of already-fetched items, with the totals the JSON:API collection needs.
 *
 * @implements \IteratorAggregate<int, object|array>
 */
final class Paginator implements PaginatorInterface, \IteratorAggregate
{
    /**
     * @param list<object|array> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $page,
        private readonly int $size,
        private readonly int $total,
    ) {
    }

    public function getCurrentPage(): float
    {
        return (float) $this->page;
    }

    public function getItemsPerPage(): float
    {
        return (float) $this->size;
    }

    public function getLastPage(): float
    {
        return (float) max(1, (int) ceil($this->total / $this->size));
    }

    public function getTotalItems(): float
    {
        return (float) $this->total;
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
