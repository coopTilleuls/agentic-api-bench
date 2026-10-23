<?php

namespace App\Bookshop;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Connection;

/**
 * SQL access to the existing bookshop tables.
 */
final class Store
{
    /** Columns of a book row, with its author's name. */
    private const BOOK_SELECT = 'b.id, b.title, b.price_cents, b.published, b.published_at, b.stock, b.author_id, a.name AS author_name';

    /** Sortable book fields and their column. */
    public const BOOK_SORT = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title COLLATE "C"',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    // Authors

    public function countAuthors(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM author');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function authorPage(int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
             FROM author a ORDER BY a.id LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAuthor(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
             FROM author a WHERE a.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : $row;
    }

    /**
     * Ids of the published books of each author, in id order.
     *
     * @param list<int> $authorIds
     * @return array<int, list<int>>
     */
    public function publishedBookIdsByAuthor(array $authorIds): array
    {
        $map = array_fill_keys($authorIds, []);
        if ([] === $authorIds) {
            return $map;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, author_id FROM book WHERE published AND author_id IN (:ids) ORDER BY id',
            ['ids' => $authorIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $map[(int) $row['author_id']][] = (int) $row['id'];
        }

        return $map;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function authorsByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, name FROM author WHERE id IN (:ids) ORDER BY id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    // Books

    /**
     * @param array<string, mixed> $filters keys: title, author, minPrice, maxPrice, publishedAfter, publishedBefore, inStock
     * @return array{string, array<string, mixed>}
     */
    private function bookWhere(array $filters): array
    {
        $conditions = ['b.published'];
        $params = [];

        if (null !== ($filters['title'] ?? null)) {
            $conditions[] = 'POSITION(LOWER(:title) IN LOWER(b.title)) > 0';
            $params['title'] = $filters['title'];
        }
        if (null !== ($filters['author'] ?? null)) {
            $conditions[] = 'a.name = :author';
            $params['author'] = $filters['author'];
        }
        if (null !== ($filters['minPrice'] ?? null)) {
            $conditions[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = $filters['minPrice'];
        }
        if (null !== ($filters['maxPrice'] ?? null)) {
            $conditions[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = $filters['maxPrice'];
        }
        if (null !== ($filters['publishedAfter'] ?? null)) {
            $conditions[] = 'b.published_at >= :publishedAfter';
            $params['publishedAfter'] = $filters['publishedAfter'];
        }
        if (null !== ($filters['publishedBefore'] ?? null)) {
            $conditions[] = 'b.published_at <= :publishedBefore';
            $params['publishedBefore'] = $filters['publishedBefore'];
        }
        if (null !== ($filters['inStock'] ?? null)) {
            $conditions[] = $filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return ['FROM book b JOIN author a ON a.id = b.author_id WHERE '.implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countBooks(array $filters): int
    {
        [$where, $params] = $this->bookWhere($filters);

        return (int) $this->connection->fetchOne('SELECT COUNT(*) '.$where, $params);
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<array{field: string, descending: bool}> $sorts
     * @return list<array<string, mixed>>
     */
    public function books(array $filters, array $sorts, int $limit, int $offset): array
    {
        [$where, $params] = $this->bookWhere($filters);

        $order = [];
        foreach ($sorts as $sort) {
            $order[] = self::BOOK_SORT[$sort['field']].($sort['descending'] ? ' DESC' : ' ASC');
        }
        $order[] = 'b.id ASC';

        $params['limit'] = $limit;
        $params['offset'] = $offset;

        return $this->connection->fetchAllAssociative(
            'SELECT '.self::BOOK_SELECT.' '.$where.' ORDER BY '.implode(', ', $order).' LIMIT :limit OFFSET :offset',
            $params,
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    public function publishedBooksByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT '.self::BOOK_SELECT.' FROM book b JOIN author a ON a.id = b.author_id
             WHERE b.published AND b.id IN (:ids) ORDER BY b.id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * Any book, published or not.
     *
     * @return array<string, mixed>|null
     */
    public function findBook(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::BOOK_SELECT.' FROM book b JOIN author a ON a.id = b.author_id WHERE b.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, mixed> $changes column => value
     */
    public function updateBook(int $id, array $changes): void
    {
        $this->connection->update('book', $changes, ['id' => $id]);
    }

    // Customers and purchases

    public function customerExists(int $id): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM customer WHERE id = :id', ['id' => $id]);
    }

    public function countPurchases(int $customerId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = :customer', ['customer' => $customerId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function purchasePage(int $customerId, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = :customer
             ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset',
            ['customer' => $customerId, 'limit' => $limit, 'offset' => $offset],
            ['customer' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchase(int $customerId, int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = :id AND customer_id = :customer',
            ['id' => $id, 'customer' => $customerId],
        );

        return false === $row ? null : $row;
    }

    /**
     * Lines of each purchase, keyed by purchase id.
     *
     * @param list<int> $purchaseIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesByPurchase(array $purchaseIds): array
    {
        $map = array_fill_keys($purchaseIds, []);
        if ([] === $purchaseIds) {
            return $map;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (:ids) ORDER BY pl.id',
            ['ids' => $purchaseIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $map[(int) $row['purchase_id']][] = $row;
        }

        return $map;
    }

    // Sales report

    /**
     * @return list<array<string, mixed>>
     */
    public function salesBetween(\DateTimeImmutable $from, \DateTimeImmutable $untilExclusive): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT b.id AS book_id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= :from AND p.created_at < :until
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title COLLATE "C" ASC, b.id ASC',
            ['from' => $from->format(\DATE_ATOM), 'until' => $untilExclusive->format(\DATE_ATOM)],
        );
    }
}
