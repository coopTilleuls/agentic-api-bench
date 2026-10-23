<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Author;
use App\ApiResource\Book;
use App\Http\QueryParser;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<Author|null>
 */
final class AuthorProvider implements ProviderInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly QueryParser $queryParser,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator|Author|null
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($operation instanceof CollectionOperationInterface) {
            $query = $this->queryParser->parseList($request, sortable: [], includable: ['books'], filters: []);

            return new TraversablePaginator(
                new \ArrayIterator($this->withBooks($this->repository->findAuthors($query->offset(), $query->size))),
                (float) $query->page,
                (float) $query->size,
                (float) $this->repository->countAuthors(),
            );
        }

        $this->queryParser->parseInclude($request, ['books']);

        $id = QueryParser::identifier($uriVariables['id'] ?? null);
        $row = null === $id ? null : $this->repository->findAuthor($id);

        return null === $row ? null : ($this->withBooks([$row])[0]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Author>
     */
    private function withBooks(array $rows): array
    {
        $authors = [];
        foreach ($rows as $row) {
            $author = Author::fromRow($row);
            $authors[$author->id] = $author;
        }

        foreach ($this->repository->publishedBooksOf(array_keys($authors)) as $row) {
            $authors[(int) $row['author_id']]->books[] = Book::fromRow($row);
        }

        return array_values($authors);
    }
}
