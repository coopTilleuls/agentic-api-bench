<?php

namespace App\Repository;

use App\JsonApi\Query;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads of the published books. Unpublished books are never returned here.
 */
final class BookRepository
{
    private const SELECT = 'SELECT b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock, b.published FROM book b';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed>                   $filters
     * @param list<array{0: string, 1: string}>      $sort
     *
     * @return array{0: list<array<string, mixed>>, 1: int} the books of the page and the total of matching books
     */
    public function paginate(array $filters, array $sort, int $offset, int $size): array
    {
        [$where, $params] = $this->where($filters);

        $order = array_map(static fn (array $s): string => $s[0].' '.$s[1], $sort);
        $order[] = 'b.id ASC';

        $rows = $this->db->fetchAllAssociative(
            self::SELECT.' JOIN author a ON a.id = b.author_id WHERE '.$where
            .' ORDER BY '.implode(', ', $order).' LIMIT '.$size.' OFFSET '.$offset,
            $params,
        );
        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id WHERE '.$where,
            $params,
        );

        return [$rows, $total];
    }

    /** @return array<string, mixed>|null */
    public function findPublished(int $id): ?array
    {
        $row = $this->db->fetchAssociative(self::SELECT.' WHERE b.published AND b.id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /** @return list<array<string, mixed>> the published books of an author, in id order */
    public function publishedByAuthor(int $authorId): array
    {
        return $this->db->fetchAllAssociative(
            self::SELECT.' WHERE b.published AND b.author_id = ? ORDER BY b.id',
            [$authorId],
        );
    }

    /**
     * Any book, published or not, for a write.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative(self::SELECT.' WHERE b.id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /** @param array<string, int|string> $columns column => value */
    public function update(int $id, array $columns): void
    {
        $assignments = [];
        $params = [];
        foreach ($columns as $column => $value) {
            $assignments[] = $column.' = ?';
            $params[] = $value;
        }
        $params[] = $id;

        $this->db->executeStatement('UPDATE book SET '.implode(', ', $assignments).' WHERE id = ?', $params);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $filters): array
    {
        $clauses = ['b.published'];
        $params = [];

        if (isset($filters['title'])) {
            $clauses[] = 'b.title ILIKE ?';
            $params[] = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['title']).'%';
        }
        if (isset($filters['author'])) {
            $clauses[] = 'a.name = ?';
            $params[] = $filters['author'];
        }
        if (isset($filters['minPrice'])) {
            $clauses[] = 'b.price_cents >= ?';
            $params[] = $filters['minPrice'];
        }
        if (isset($filters['maxPrice'])) {
            $clauses[] = 'b.price_cents <= ?';
            $params[] = $filters['maxPrice'];
        }
        if (isset($filters['publishedAfter'])) {
            $clauses[] = 'b.published_at >= ?';
            $params[] = $filters['publishedAfter'];
        }
        if (isset($filters['publishedBefore'])) {
            $clauses[] = 'b.published_at <= ?';
            $params[] = $filters['publishedBefore'];
        }
        if (isset($filters['inStock'])) {
            $clauses[] = $filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function findPublishedByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            self::SELECT.' WHERE b.published AND b.id IN (?) ORDER BY b.id',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
    }
}
