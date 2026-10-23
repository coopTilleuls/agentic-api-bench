<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * SQL access to the existing bookshop tables. Read methods return rows, shaped by the providers.
 */
final class BookshopRepository
{
    /**
     * Subquery counting the published books of an author.
     */
    private const PUBLISHED_BOOKS = '(SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published = TRUE)';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function authors(int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT a.id, a.name, '.self::PUBLISHED_BOOKS.' AS published_books FROM author a ORDER BY a.id LIMIT ? OFFSET ?',
            [$limit, $offset],
        );
    }

    public function authorCount(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
    }

    /**
     * @return array<string, mixed>|false
     */
    public function author(int $id): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT a.id, a.name, '.self::PUBLISHED_BOOKS.' AS published_books FROM author a WHERE a.id = ?',
            [$id],
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $orderBy
     *
     * @return list<array<string, mixed>>
     */
    public function books(array $filters, array $orderBy, int $limit, int $offset): array
    {
        [$where, $params] = $this->bookWhere($filters);

        return $this->db->fetchAllAssociative(
            $this->bookSelect().' WHERE '.$where.' ORDER BY '.implode(', ', $orderBy).' LIMIT ? OFFSET ?',
            [...$params, $limit, $offset],
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function bookCount(array $filters): int
    {
        [$where, $params] = $this->bookWhere($filters);

        return (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id WHERE '.$where,
            $params,
        );
    }

    /**
     * @return array<string, mixed>|false
     */
    public function publishedBook(int $id): array|false
    {
        return $this->db->fetchAssociative(
            $this->bookSelect().' WHERE b.id = ? AND b.published = TRUE',
            [$id],
        );
    }

    /**
     * Any book, published or not, for an update.
     *
     * @return array<string, mixed>|false
     */
    public function book(int $id): array|false
    {
        return $this->db->fetchAssociative($this->bookSelect().' WHERE b.id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function publishedBooksOf(int $authorId): array
    {
        return $this->db->fetchAllAssociative(
            $this->bookSelect().' WHERE b.author_id = ? AND b.published = TRUE ORDER BY b.id',
            [$authorId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function purchasesOf(int $customerId, int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.customer_id = ? ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?',
            [$customerId, $limit, $offset],
        );
    }

    public function purchaseCountOf(int $customerId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
    }

    /**
     * @return array<string, mixed>|false
     */
    public function purchaseOf(int $customerId, int $id): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.id = ? AND p.customer_id = ?',
            [$id, $customerId],
        );
    }

    /**
     * @param list<int> $purchaseIds
     *
     * @return list<array<string, mixed>>
     */
    public function linesOf(array $purchaseIds): array
    {
        if (!$purchaseIds) {
            return [];
        }

        return $this->db->executeQuery(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (:ids) ORDER BY pl.id',
            ['ids' => $purchaseIds],
            ['ids' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();
    }

    /**
     * Sales between two instants, one row per book, best sellers first.
     *
     * @return list<array<string, mixed>>
     */
    public function salesBetween(\DateTimeImmutable $from, \DateTimeImmutable $untilExclusive): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title, b.id',
            [$from->format(\DateTimeInterface::ATOM), $untilExclusive->format(\DateTimeInterface::ATOM)],
        );
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{string, list<mixed>}
     */
    private function bookWhere(array $filters): array
    {
        $where = ['b.published = TRUE'];
        $params = [];

        if (null !== $filters['title']) {
            $where[] = "b.title ILIKE ? ESCAPE '!'";
            $params[] = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['title']).'%';
        }
        if (null !== $filters['author']) {
            $where[] = 'a.name = ?';
            $params[] = $filters['author'];
        }
        if (null !== $filters['minPrice']) {
            $where[] = 'b.price_cents >= ?';
            $params[] = $filters['minPrice'];
        }
        if (null !== $filters['maxPrice']) {
            $where[] = 'b.price_cents <= ?';
            $params[] = $filters['maxPrice'];
        }
        if (null !== $filters['publishedAfter']) {
            $where[] = 'b.published_at >= ?';
            $params[] = $filters['publishedAfter'];
        }
        if (null !== $filters['publishedBefore']) {
            $where[] = 'b.published_at <= ?';
            $params[] = $filters['publishedBefore'];
        }
        if (null !== $filters['inStock']) {
            $where[] = $filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [implode(' AND ', $where), $params];
    }

    private function bookSelect(): string
    {
        return 'SELECT b.id, b.title, b.price_cents, b.published::int AS published, b.published_at, b.stock, b.author_id,
                a.name AS author_name, '.self::PUBLISHED_BOOKS.' AS author_published_books
            FROM book b JOIN author a ON a.id = b.author_id';
    }
}
