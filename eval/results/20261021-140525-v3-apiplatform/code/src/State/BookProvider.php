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
 * @implements ProviderInterface<Book>
 */
final class BookProvider implements ProviderInterface
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];

        if ($operation instanceof CollectionOperationInterface) {
            JsonApiQuery::include($request, ['author']);
            $filters = [
                'title' => JsonApiQuery::filter($request, 'title'),
                'author' => JsonApiQuery::filter($request, 'author'),
                'minPrice' => JsonApiQuery::integerFilter($request, 'minPrice'),
                'maxPrice' => JsonApiQuery::integerFilter($request, 'maxPrice'),
                'publishedAfter' => JsonApiQuery::dateFilter($request, 'publishedAfter'),
                'publishedBefore' => JsonApiQuery::dateFilter($request, 'publishedBefore'),
                'inStock' => JsonApiQuery::booleanFilter($request, 'inStock'),
            ];
            $sort = JsonApiQuery::sort($request, ['priceCents', 'title', 'publishedAt']);
            $page = JsonApiQuery::page($request);

            $result = $this->repository->findPublishedBooks($filters, $sort, $page['number'], $page['size']);
            $items = array_map(fn (array $row): Book => self::book($row), $result['rows']);

            return new Page($items, $result['total'], $page['number'], $page['size']);
        }

        JsonApiQuery::include($request, ['author']);
        $id = (int) $uriVariables['id'];
        // Unpublished books are only reachable through PATCH.
        $row = $this->repository->findBook($id, 'PATCH' !== $operation->getMethod());
        if (null === $row) {
            throw ApiException::single(404, 'Not Found', sprintf('Book %d not found.', $id));
        }

        return self::book($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function book(array $row): Book
    {
        $author = new Author();
        $author->id = (int) $row['author_id'];
        $author->name = $row['author_name'];
        $author->publishedBooks = (int) $row['author_published_books'];

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
