<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Book;
use App\Http\QueryParser;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<Book|null>
 */
final class BookProvider implements ProviderInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly QueryParser $queryParser,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator|Book|null
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($operation instanceof CollectionOperationInterface) {
            $query = $this->queryParser->parseList(
                $request,
                sortable: ['priceCents', 'title', 'publishedAt'],
                includable: ['author'],
                filters: [
                    'title' => 'string',
                    'author' => 'string',
                    'minPrice' => 'int',
                    'maxPrice' => 'int',
                    'publishedAfter' => 'date',
                    'publishedBefore' => 'date',
                    'inStock' => 'bool',
                ],
            );

            $rows = $this->repository->findBooks($query->filters, $query->sort, $query->offset(), $query->size);

            return new TraversablePaginator(
                new \ArrayIterator(array_map(Book::fromRow(...), $rows)),
                (float) $query->page,
                (float) $query->size,
                (float) $this->repository->countBooks($query->filters),
            );
        }

        $this->queryParser->parseInclude($request, ['author']);

        $id = QueryParser::identifier($uriVariables['id'] ?? null);
        if (null === $id) {
            return null;
        }

        // Unpublished books are only reachable for PATCH.
        $row = $this->repository->findBook($id, $operation instanceof Patch);

        return null === $row ? null : Book::fromRow($row);
    }
}
