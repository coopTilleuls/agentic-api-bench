<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Author;
use App\ApiResource\Book;
use App\Exception\ApiException;
use App\Http\JsonApiQuery;
use App\Pagination\Page;
use App\Service\BookshopRepository;

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
        $request = $context['request'];
        $include = JsonApiQuery::include($request, ['books']);

        if ($operation instanceof CollectionOperationInterface) {
            $page = JsonApiQuery::page($request);
            $result = $this->repository->findAuthors($page['number'], $page['size']);
            $items = array_map(fn (array $row): Author => $this->author($row), $result['rows']);

            return new Page($items, $result['total'], $page['number'], $page['size']);
        }

        $row = $this->repository->findAuthor((int) $uriVariables['id']);
        if (null === $row) {
            throw ApiException::single(404, 'Not Found', sprintf('Author %s not found.', $uriVariables['id']));
        }

        $author = $this->author($row);
        $author->books = array_map(fn (array $book): Book => $this->book($book, $author), $this->repository->findPublishedBooksOfAuthor($author->id));

        return $author;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function author(array $row): Author
    {
        $author = new Author();
        $author->id = (int) $row['id'];
        $author->name = $row['name'];
        $author->publishedBooks = (int) $row['published_books'];

        return $author;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function book(array $row, Author $author): Book
    {
        $book = new Book();
        $book->id = (int) $row['id'];
        $book->title = $row['title'];
        $book->priceCents = (int) $row['price_cents'];
        $book->publishedAt = $row['published_at'];
        $book->stock = (int) $row['stock'];
        $book->author = $author;

        return $book;
    }
}
