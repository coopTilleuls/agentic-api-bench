<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Author;
use App\JsonApi\Paginator;
use App\JsonApi\QueryParameters;
use App\Repository\BookshopRepository;

/**
 * @implements ProviderInterface<Author>
 */
final class AuthorProvider implements ProviderInterface
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $query = new QueryParameters($context['request']);

        if ($operation instanceof CollectionOperationInterface) {
            $page = $query->page();
            $query->sort([]);
            $query->include(['books']);

            $rows = $this->repository->authors($page['size'], ($page['number'] - 1) * $page['size']);
            $authors = array_map(fn (array $row): Author => $this->withBooks(Mapper::author($row)), $rows);

            return new Paginator($authors, $this->repository->authorCount(), $page['number'], $page['size']);
        }

        $query->include(['books']);
        $row = $this->repository->author((int) $uriVariables['id']);

        return false === $row ? null : $this->withBooks(Mapper::author($row));
    }

    /**
     * The published books of the author, in id order.
     */
    private function withBooks(Author $author): Author
    {
        $author->books = array_map(Mapper::book(...), $this->repository->publishedBooksOf($author->id));

        return $author;
    }
}
