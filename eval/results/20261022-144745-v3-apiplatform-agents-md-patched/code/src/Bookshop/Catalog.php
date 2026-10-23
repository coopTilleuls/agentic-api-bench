<?php

namespace App\Bookshop;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads and updates authors and books.
 */
final class Catalog
{
    /** Largest value of the integer columns. */
    public const MAX_INTEGER = 2147483647;

    /** SQL column by sortable field of the books. */
    public const BOOK_SORT = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title COLLATE "C"',
        'publishedAt' => 'b.published_at',
    ];

    private const AUTHOR_COLUMNS = 'a.id, a.name, (SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published) AS published_books';
    private const BOOK_COLUMNS = 'b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock, b.published::int AS published';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function authors(int $number, int $size): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
        $rows = $this->db->fetchAllAssociative(
            'SELECT '.self::AUTHOR_COLUMNS.' FROM author a ORDER BY a.id LIMIT ? OFFSET ?',
            [$size, ($number - 1) * $size],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function author(int $id): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT '.self::AUTHOR_COLUMNS.' FROM author a WHERE a.id = ?',
            [$id],
            [ParameterType::INTEGER],
        ) ?: null;
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

        return $this->db->fetchAllAssociative(
            'SELECT '.self::AUTHOR_COLUMNS.' FROM author a WHERE a.id IN (?) ORDER BY a.id',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
    }

    /**
     * The published books of the given authors, in id order.
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

        return $this->db->fetchAllAssociative(
            'SELECT '.self::BOOK_COLUMNS.' FROM book b WHERE b.published AND b.author_id IN (?) ORDER BY b.id',
            [$authorIds],
            [ArrayParameterType::INTEGER],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function book(int $id): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT '.self::BOOK_COLUMNS.' FROM book b WHERE b.id = ?',
            [$id],
            [ParameterType::INTEGER],
        ) ?: null;
    }

    /**
     * @param array<string, mixed>  $criteria see BookFilters
     * @param list<array{0: string, 1: 'ASC'|'DESC'}> $sort
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function books(array $criteria, array $sort, int $number, int $size): array
    {
        [$conditions, $params, $types] = $this->bookConditions($criteria);
        $from = 'FROM book b JOIN author a ON a.id = b.author_id WHERE '.implode(' AND ', $conditions);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) '.$from, $params, $types);

        $order = array_map(static fn (array $term): string => $term[0].' '.$term[1], $sort);
        $order[] = 'b.id ASC';

        $rows = $this->db->fetchAllAssociative(
            'SELECT '.self::BOOK_COLUMNS.' '.$from.' ORDER BY '.implode(', ', $order).' LIMIT ? OFFSET ?',
            [...$params, $size, ($number - 1) * $size],
            [...$types, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return ['total' => $total, 'rows' => $rows];
    }

    public function updateBook(int $id, ?string $title, ?int $priceCents, ?int $stock): void
    {
        $this->db->executeStatement(
            'UPDATE book SET title = COALESCE(?, title), price_cents = COALESCE(?, price_cents), stock = COALESCE(?, stock) WHERE id = ?',
            [$title, $priceCents, $stock, $id],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * @param array<string, mixed> $criteria
     *
     * @return array{0: list<string>, 1: list<mixed>, 2: list<ParameterType>}
     */
    private function bookConditions(array $criteria): array
    {
        $conditions = ['b.published'];
        $params = [];
        $types = [];

        if (isset($criteria['title'])) {
            $conditions[] = 'POSITION(? IN LOWER(b.title)) > 0';
            $params[] = mb_strtolower($criteria['title']);
            $types[] = ParameterType::STRING;
        }
        if (isset($criteria['author'])) {
            $conditions[] = 'a.name = ?';
            $params[] = $criteria['author'];
            $types[] = ParameterType::STRING;
        }
        if (isset($criteria['minPrice'])) {
            $conditions[] = 'b.price_cents >= ?';
            $params[] = $criteria['minPrice'];
            $types[] = ParameterType::INTEGER;
        }
        if (isset($criteria['maxPrice'])) {
            $conditions[] = 'b.price_cents <= ?';
            $params[] = $criteria['maxPrice'];
            $types[] = ParameterType::INTEGER;
        }
        if (isset($criteria['publishedAfter'])) {
            $conditions[] = 'b.published_at >= ?';
            $params[] = $criteria['publishedAfter'];
            $types[] = ParameterType::STRING;
        }
        if (isset($criteria['publishedBefore'])) {
            $conditions[] = 'b.published_at <= ?';
            $params[] = $criteria['publishedBefore'];
            $types[] = ParameterType::STRING;
        }
        if (isset($criteria['inStock'])) {
            $conditions[] = $criteria['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [$conditions, $params, $types];
    }
}
