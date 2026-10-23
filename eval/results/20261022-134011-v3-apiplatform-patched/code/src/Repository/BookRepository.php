<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class BookRepository
{
    private const COLUMNS = 'b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id, b.published';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $filters see BookController::filters()
     */
    public function count(array $filters): int
    {
        [$where, $params] = self::criteria($filters);

        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id WHERE $where",
            $params,
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $orderBy ORDER BY terms, before the id tie-break
     *
     * @return list<array<string, mixed>>
     */
    public function page(array $filters, array $orderBy, int $limit, int $offset): array
    {
        [$where, $params] = self::criteria($filters);
        $order = implode(', ', [...$orderBy, 'b.id ASC']);

        return $this->db->fetchAllAssociative(
            "SELECT ".self::COLUMNS." FROM book b JOIN author a ON a.id = b.author_id WHERE $where ORDER BY $order LIMIT $limit OFFSET $offset",
            $params,
        );
    }

    /**
     * A published book.
     *
     * @return array<string, mixed>|null
     */
    public function findPublished(int $id): ?array
    {
        $row = $this->findAny($id);

        return null !== $row && $row['published'] ? $row : null;
    }

    /**
     * Any book, published or not.
     *
     * @return array<string, mixed>|null
     */
    public function findAny(int $id): ?array
    {
        return $this->db->fetchAssociative('SELECT '.self::COLUMNS.' FROM book b WHERE b.id = ?', [$id]) ?: null;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function publishedByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM book b WHERE b.published = true AND b.id IN ('.Placeholders::for($ids).') ORDER BY b.id',
            array_values($ids),
        );
    }

    /**
     * Published book ids per author, in id order.
     *
     * @param list<int|string> $authorIds
     *
     * @return array<int, list<int>>
     */
    public function publishedIdsByAuthor(array $authorIds): array
    {
        if ([] === $authorIds) {
            return [];
        }

        $map = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, author_id FROM book WHERE published = true AND author_id IN ('.Placeholders::for($authorIds).') ORDER BY id',
            array_values($authorIds),
        );
        foreach ($rows as $row) {
            $map[(int) $row['author_id']][] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $set column => value
     */
    public function update(int $id, array $set): void
    {
        $assignments = [];
        $params = [];
        foreach ($set as $column => $value) {
            $assignments[] = "$column = ?";
            $params[] = $value;
        }
        $params[] = $id;

        $this->db->executeStatement(
            'UPDATE book SET '.implode(', ', $assignments).' WHERE id = ?',
            $params,
        );
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function criteria(array $filters): array
    {
        $where = ['b.published = true'];
        $params = [];

        if (null !== ($filters['title'] ?? null)) {
            $where[] = 'position(lower(:title) in lower(b.title)) > 0';
            $params['title'] = $filters['title'];
        }
        if (null !== ($filters['author'] ?? null)) {
            $where[] = 'a.name = :author';
            $params['author'] = $filters['author'];
        }
        if (null !== ($filters['minPrice'] ?? null)) {
            $where[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = $filters['minPrice'];
        }
        if (null !== ($filters['maxPrice'] ?? null)) {
            $where[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = $filters['maxPrice'];
        }
        if (null !== ($filters['publishedAfter'] ?? null)) {
            $where[] = 'b.published_at >= CAST(:publishedAfter AS date)';
            $params['publishedAfter'] = $filters['publishedAfter'];
        }
        if (null !== ($filters['publishedBefore'] ?? null)) {
            $where[] = 'b.published_at <= CAST(:publishedBefore AS date)';
            $params['publishedBefore'] = $filters['publishedBefore'];
        }
        if (null !== ($filters['inStock'] ?? null)) {
            $where[] = $filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [implode(' AND ', $where), $params];
    }
}
