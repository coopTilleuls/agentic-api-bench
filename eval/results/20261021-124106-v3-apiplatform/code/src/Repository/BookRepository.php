<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Reads authors and books. Only published books are visible through the API, except through findBook() which
 * PATCH uses for any book.
 */
final class BookRepository
{
    private const BOOK_COLUMNS = 'b.id, b.title, b.author_id, b.published, b.price_cents, b.published_at, b.stock';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countBooks(array $filters): int
    {
        [$where, $params] = self::bookFilter($filters);

        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id WHERE $where",
            $params,
        );
    }

    /**
     * @param array<string, mixed>                  $filters
     * @param list<array{0: string, 1: string}>     $orders
     *
     * @return list<array<string, mixed>>
     */
    public function findBooks(array $filters, array $orders, int $offset, int $limit): array
    {
        [$where, $params] = self::bookFilter($filters);
        $order = [];
        foreach ($orders as [$column, $direction]) {
            $order[] = "$column $direction";
        }
        $order[] = 'b.id ASC';

        return $this->db->fetchAllAssociative(
            'SELECT '.self::BOOK_COLUMNS.' FROM book b JOIN author a ON a.id = b.author_id'
            ." WHERE $where ORDER BY ".implode(', ', $order)." LIMIT $limit OFFSET $offset",
            $params,
        );
    }

    /** @return array<string, mixed>|null */
    public function findBook(int $id): ?array
    {
        $row = $this->db->fetchAssociative('SELECT '.self::BOOK_COLUMNS.' FROM book b WHERE b.id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, int|string> $changes column => value
     */
    public function updateBook(int $id, array $changes): void
    {
        if ([] === $changes) {
            return;
        }
        $sets = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $sets[] = "$column = ?";
            $params[] = $value;
        }
        $params[] = $id;
        $this->db->executeStatement('UPDATE book SET '.implode(', ', $sets).' WHERE id = ?', $params);
    }

    public function countAuthors(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
    }

    /** @return list<array<string, mixed>> */
    public function findAuthors(int $offset, int $limit): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books'
            ." FROM author a ORDER BY a.id LIMIT $limit OFFSET $offset",
        );
    }

    /** @return array<string, mixed>|null */
    public function findAuthor(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books'
            .' FROM author a WHERE a.id = ?',
            [$id],
        );

        return false === $row ? null : $row;
    }

    /**
     * Published books of the given authors, in id order.
     *
     * @param list<int> $authorIds
     *
     * @return list<array<string, mixed>>
     */
    public function publishedBooksOf(array $authorIds): array
    {
        if ([] === $authorIds) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($authorIds), '?'));

        return $this->db->fetchAllAssociative(
            'SELECT '.self::BOOK_COLUMNS." FROM book b WHERE b.published AND b.author_id IN ($marks) ORDER BY b.id",
            $authorIds,
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function authorsByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($ids), '?'));

        return $this->db->fetchAllAssociative("SELECT id, name FROM author WHERE id IN ($marks) ORDER BY id", $ids);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{0: string, 1: list<mixed>} SQL condition and its parameters
     */
    private static function bookFilter(array $filters): array
    {
        $conditions = ['b.published'];
        $params = [];
        if (isset($filters['title'])) {
            $conditions[] = 'POSITION(LOWER(?) IN LOWER(b.title)) > 0';
            $params[] = $filters['title'];
        }
        if (isset($filters['author'])) {
            $conditions[] = 'a.name = ?';
            $params[] = $filters['author'];
        }
        if (isset($filters['minPrice'])) {
            $conditions[] = 'b.price_cents >= ?';
            $params[] = $filters['minPrice'];
        }
        if (isset($filters['maxPrice'])) {
            $conditions[] = 'b.price_cents <= ?';
            $params[] = $filters['maxPrice'];
        }
        if (isset($filters['publishedAfter'])) {
            $conditions[] = 'b.published_at >= ?';
            $params[] = $filters['publishedAfter'];
        }
        if (isset($filters['publishedBefore'])) {
            $conditions[] = 'b.published_at <= ?';
            $params[] = $filters['publishedBefore'];
        }
        if (isset($filters['inStock'])) {
            $conditions[] = $filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [implode(' AND ', $conditions), $params];
    }
}
