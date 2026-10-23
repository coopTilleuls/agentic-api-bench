<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Book;
use App\JsonApi\Paginator;
use App\JsonApi\QueryParameters;
use App\Repository\BookshopRepository;

/**
 * Reads published books. A PATCH also reads unpublished books, as they can be updated.
 *
 * @implements ProviderInterface<Book>
 */
final class BookProvider implements ProviderInterface
{
    /**
     * Sortable fields, mapped to their columns.
     */
    private const SORT = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $query = new QueryParameters($context['request']);

        if ($operation instanceof CollectionOperationInterface) {
            $page = $query->page();
            $sort = $query->sort(array_keys(self::SORT));
            $include = $query->include(['author']);
            $filters = [
                'title' => $query->text('title'),
                'author' => $query->text('author'),
                'minPrice' => $query->integer('minPrice'),
                'maxPrice' => $query->integer('maxPrice'),
                'publishedAfter' => $query->date('publishedAfter'),
                'publishedBefore' => $query->date('publishedBefore'),
                'inStock' => $query->boolean('inStock'),
            ];

            $orderBy = [];
            foreach ($sort as $field => $descending) {
                $orderBy[] = self::SORT[$field].($descending ? ' DESC' : ' ASC');
            }
            $orderBy[] = 'b.id ASC';

            $rows = $this->repository->books($filters, $orderBy, $page['size'], ($page['number'] - 1) * $page['size']);

            return new Paginator(
                array_map(Mapper::book(...), $rows),
                $this->repository->bookCount($filters),
                $page['number'],
                $page['size'],
            );
        }

        $query->include(['author']);
        $id = (int) $uriVariables['id'];
        $row = $operation instanceof Patch ? $this->repository->book($id) : $this->repository->publishedBook($id);

        return false === $row ? null : Mapper::book($row);
    }
}
