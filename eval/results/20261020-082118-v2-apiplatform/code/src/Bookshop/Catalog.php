<?php

namespace App\Bookshop;

use App\Http\ApiError;
use App\Http\Input;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Authors and published books, plus the stock/price maintenance of books.
 */
final class Catalog
{
    /** Public sort keys mapped to SQL columns. */
    public const SORTABLE = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function authors(int $page, int $perPage): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.name, COUNT(b.id) AS published_books
             FROM author a
             LEFT JOIN book b ON b.author_id = a.id AND b.published = TRUE
             GROUP BY a.id, a.name
             ORDER BY a.id
             LIMIT ? OFFSET ?',
            [$perPage, ($page - 1) * $perPage],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'publishedBooks' => (int) $row['published_books'],
        ], $rows);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null null when the author does not exist
     */
    public function author(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id, name FROM author WHERE id = ?', [$id]);
        if (false === $row) {
            return null;
        }

        $books = $this->db->fetchAllAssociative(
            'SELECT id, title FROM book WHERE author_id = ? AND published = TRUE ORDER BY id',
            [$id],
        );

        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'books' => array_map(static fn (array $book): array => [
                'id' => (int) $book['id'],
                'title' => $book['title'],
            ], $books),
        ];
    }

    /**
     * @param array{title: ?string, author: ?string, priceGte: ?int, priceLte: ?int, publishedAfter: ?string, publishedBefore: ?string, inStock: ?bool} $filters
     * @param list<array{string, string}>                                                                                                         $orders  [column, direction] pairs
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function books(array $filters, array $orders, int $page, int $perPage): array
    {
        $total = (int) $this->filtered($filters)->select('COUNT(*)')->executeQuery()->fetchOne();

        $orderBy = [];
        foreach ($orders as [$column, $direction]) {
            $orderBy[] = self::SORTABLE[$column].' '.strtoupper($direction);
        }
        $orderBy[] = 'b.id ASC';

        $query = $this->filtered($filters)
            ->select('b.id', 'b.title', 'b.price_cents', 'b.published_at', 'b.stock', 'a.id AS author_id', 'a.name AS author_name')
            ->orderBy(implode(', ', $orderBy))
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $items = array_map(self::bookShape(...), $query->executeQuery()->fetchAllAssociative());

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null null when the book does not exist or is unpublished
     */
    public function book(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, a.id AS author_id, a.name AS author_name
             FROM book b JOIN author a ON a.id = b.author_id
             WHERE b.id = ? AND b.published = TRUE',
            [$id],
        );

        return false === $row ? null : self::bookShape($row);
    }

    /**
     * Applies the given fields to a book, published or not.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>|null the book, or null when it is unpublished (answered 204)
     */
    public function update(int $id, array $body): ?array
    {
        $exists = $this->db->fetchOne('SELECT COUNT(*) FROM book WHERE id = ?', [$id]);
        if (0 === (int) $exists) {
            throw new ApiError(404, 'Book not found.');
        }

        $fields = [];
        if (\array_key_exists('title', $body)) {
            if (!\is_string($body['title']) || '' === trim($body['title'])) {
                throw new ApiError(422, 'The title must be a non-empty string.');
            }
            $fields['title'] = $body['title'];
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $key => $column) {
            if (!\array_key_exists($key, $body)) {
                continue;
            }
            if (!\is_int($body[$key]) || $body[$key] < 0 || $body[$key] > Input::MAX_INT) {
                throw new ApiError(422, sprintf('%s must be a non-negative integer.', $key));
            }
            $fields[$column] = $body[$key];
        }

        if ([] !== $fields) {
            $this->db->update('book', $fields, ['id' => $id]);
        }

        return $this->book($id);
    }

    /**
     * @param array{title: ?string, author: ?string, priceGte: ?int, priceLte: ?int, publishedAfter: ?string, publishedBefore: ?string, inStock: ?bool} $filters
     */
    private function filtered(array $filters): QueryBuilder
    {
        $qb = $this->db->createQueryBuilder()
            ->from('book', 'b')
            ->join('b', 'author', 'a', 'a.id = b.author_id')
            ->where('b.published = TRUE');

        if (null !== $filters['title']) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['title']);
            $qb->andWhere('b.title ILIKE :title')->setParameter('title', '%'.$escaped.'%');
        }
        if (null !== $filters['author']) {
            $qb->andWhere('a.name = :author')->setParameter('author', $filters['author']);
        }
        if (null !== $filters['priceGte']) {
            $qb->andWhere('b.price_cents >= :priceGte')->setParameter('priceGte', $filters['priceGte']);
        }
        if (null !== $filters['priceLte']) {
            $qb->andWhere('b.price_cents <= :priceLte')->setParameter('priceLte', $filters['priceLte']);
        }
        if (null !== $filters['publishedAfter']) {
            $qb->andWhere('b.published_at >= :publishedAfter')->setParameter('publishedAfter', $filters['publishedAfter']);
        }
        if (null !== $filters['publishedBefore']) {
            $qb->andWhere('b.published_at <= :publishedBefore')->setParameter('publishedBefore', $filters['publishedBefore']);
        }
        if (true === $filters['inStock']) {
            $qb->andWhere('b.stock > 0');
        } elseif (false === $filters['inStock']) {
            $qb->andWhere('b.stock = 0');
        }

        return $qb;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function bookShape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'title' => $row['title'],
            'priceCents' => (int) $row['price_cents'],
            'publishedAt' => $row['published_at'],
            'stock' => (int) $row['stock'],
            'author' => [
                'id' => (int) $row['author_id'],
                'name' => $row['author_name'],
            ],
        ];
    }
}
