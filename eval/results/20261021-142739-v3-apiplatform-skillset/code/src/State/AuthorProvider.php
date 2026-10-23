<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Author;
use App\ApiResource\Book;
use App\JsonApi\JsonApiException;
use App\JsonApi\Paginator;
use App\JsonApi\Query;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * @implements ProviderInterface<Author>
 */
final class AuthorProvider implements ProviderInterface
{
    private const SELECT = <<<'SQL'
        SELECT a.id, a.name,
            (SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published) AS published_books
        FROM author a
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];
        $includes = Query::include($request, ['books']);
        if ($includes) {
            $request->attributes->set('_api_included', $includes);
        }

        if ($operation instanceof CollectionOperationInterface) {
            [$number, $size] = Query::page($request);

            $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM author');
            $rows = $this->connection->fetchAllAssociative(
                self::SELECT.' ORDER BY a.id LIMIT ? OFFSET ?',
                [$size, ($number - 1) * $size],
            );

            $authors = array_map($this->hydrate(...), $rows);
            $this->loadBooks($authors);

            return new Paginator($authors, $total, $number, $size);
        }

        $id = (int) $uriVariables['id'];

        $row = $this->connection->fetchAssociative(self::SELECT.' WHERE a.id = ?', [$id]);
        if (false === $row) {
            throw JsonApiException::single(404, 'Not Found', sprintf('Author %d does not exist.', $id));
        }

        $author = $this->hydrate($row);
        $this->loadBooks([$author]);

        return $author;
    }

    /**
     * Fills the books relationship of the authors with their published books, in id order.
     *
     * @param list<Author> $authors
     */
    public function loadBooks(array $authors): void
    {
        if ([] === $authors) {
            return;
        }

        $authorsById = [];
        foreach ($authors as $author) {
            $authorsById[$author->id] = $author;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, price_cents, published_at, stock, author_id FROM book WHERE published = true AND author_id IN (?) ORDER BY id',
            [array_keys($authorsById)],
            [ArrayParameterType::INTEGER],
        );

        foreach ($rows as $row) {
            $author = $authorsById[(int) $row['author_id']];

            $book = new Book();
            $book->id = (int) $row['id'];
            $book->title = $row['title'];
            $book->priceCents = (int) $row['price_cents'];
            $book->publishedAt = $row['published_at'];
            $book->stock = (int) $row['stock'];
            $book->author = $author;

            $author->books[] = $book;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Author
    {
        $author = new Author();
        $author->id = (int) $row['id'];
        $author->name = $row['name'];
        $author->publishedBooks = (int) $row['published_books'];

        return $author;
    }
}
