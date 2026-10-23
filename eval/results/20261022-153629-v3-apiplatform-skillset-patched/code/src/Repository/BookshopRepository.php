<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Reads and writes the existing bookshop tables. The schema is never changed.
 *
 * @phpstan-type BookRow array{id: int|string, title: string, price_cents: int|string, published_at: ?string, stock: int|string, author_id: int|string}
 * @phpstan-type AuthorRow array{id: int|string, name: ?string, published_books: int|string}
 */
final class BookshopRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param array{title: ?string, author: ?string, minPrice: ?int, maxPrice: ?int, publishedAfter: ?string, publishedBefore: ?string, inStock: ?bool} $filters
     * @param list<array{field: string, descending: bool}>                                                                                      $sorts
     *
     * @return array{0: list<BookRow>, 1: int} the page of published books and the total number of matching books
     */
    public function findPublishedBooks(array $filters, array $sorts, int $page, int $size): array
    {
        [$where, $params] = $this->bookConditions($filters);
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id WHERE $whereSql",
            $params,
        );

        $columns = ['priceCents' => 'b.price_cents', 'title' => 'b.title', 'publishedAt' => 'b.published_at'];
        $order = [];
        foreach ($sorts as $sort) {
            $order[] = $columns[$sort['field']].($sort['descending'] ? ' DESC' : ' ASC');
        }
        $order[] = 'b.id ASC';

        $offset = ($page - 1) * $size;
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id'
            ." FROM book b JOIN author a ON a.id = b.author_id WHERE $whereSql"
            .' ORDER BY '.implode(', ', $order)." LIMIT $size OFFSET $offset",
            $params,
        );

        return [$rows, $total];
    }

    /**
     * @return BookRow|false
     */
    public function findBook(int $id, bool $publishedOnly): array|false
    {
        $sql = 'SELECT id, title, price_cents, published_at, stock, author_id, published FROM book WHERE id = :id';
        if ($publishedOnly) {
            $sql .= ' AND published = TRUE';
        }

        return $this->connection->fetchAssociative($sql, ['id' => $id]);
    }

    /**
     * @param list<int>|null $ids restricts the authors to these ids
     *
     * @return list<AuthorRow>
     */
    public function findAuthors(?array $ids, ?int $page = null, ?int $size = null): array
    {
        $sql = 'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published = TRUE) AS published_books'
            .' FROM author a';
        $params = [];
        $types = [];
        if (null !== $ids) {
            $sql .= ' WHERE a.id IN (:ids)';
            $params['ids'] = $ids;
            $types['ids'] = ArrayParameterType::INTEGER;
        }
        $sql .= ' ORDER BY a.id ASC';
        if (null !== $page && null !== $size) {
            $sql .= ' LIMIT '.$size.' OFFSET '.(($page - 1) * $size);
        }

        return $this->connection->fetchAllAssociative($sql, $params, $types);
    }

    public function countAuthors(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM author');
    }

    /**
     * @param list<int> $authorIds
     *
     * @return list<BookRow>
     */
    public function findPublishedBooksOfAuthors(array $authorIds): array
    {
        if ([] === $authorIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, title, price_cents, published_at, stock, author_id FROM book'
            .' WHERE published = TRUE AND author_id IN (:authorIds) ORDER BY id ASC',
            ['authorIds' => $authorIds],
            ['authorIds' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * @return list<array{id: int|string, created_at: string, total_cents: int|string}>
     */
    public function findPurchases(int $customerId, int $size, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, created_at, total_cents FROM purchase WHERE customer_id = :customerId"
            ." ORDER BY created_at DESC, id DESC LIMIT $size OFFSET $offset",
            ['customerId' => $customerId],
        );
    }

    public function countPurchases(int $customerId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = :customerId', ['customerId' => $customerId]);
    }

    /**
     * @return array{id: int|string, created_at: string, total_cents: int|string}|false
     */
    public function findPurchase(int $customerId, int $id): array|false
    {
        return $this->connection->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = :id AND customer_id = :customerId',
            ['id' => $id, 'customerId' => $customerId],
        );
    }

    /**
     * @param list<int> $purchaseIds
     *
     * @return list<array{purchase_id: int|string, book_id: int|string, title: string, quantity: int|string, unit_price_cents: int|string}>
     */
    public function findPurchaseLines(array $purchaseIds): array
    {
        if ([] === $purchaseIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT l.purchase_id, l.book_id, b.title, l.quantity, l.unit_price_cents'
            .' FROM purchase_line l JOIN book b ON b.id = l.book_id'
            .' WHERE l.purchase_id IN (:purchaseIds) ORDER BY l.id ASC',
            ['purchaseIds' => $purchaseIds],
            ['purchaseIds' => ArrayParameterType::INTEGER],
        );
    }

    public function customerExists(int $customerId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM customer WHERE id = :id', ['id' => $customerId]);
    }

    /**
     * Locks the given books until the surrounding transaction ends.
     *
     * @param list<int> $bookIds
     *
     * @return list<array{id: int|string, price_cents: int|string, stock: int|string, published: bool|string}>
     */
    public function lockBooks(array $bookIds): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, price_cents, stock, published FROM book WHERE id IN (:ids) ORDER BY id FOR UPDATE',
            ['ids' => $bookIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    public function decreaseStock(int $bookId, int $quantity): void
    {
        $this->connection->executeStatement(
            'UPDATE book SET stock = stock - :quantity WHERE id = :id',
            ['quantity' => $quantity, 'id' => $bookId],
        );
    }

    public function updateBook(int $id, array $changes): void
    {
        $assignments = [];
        $params = ['id' => $id];
        foreach ($changes as $column => $value) {
            $assignments[] = "$column = :$column";
            $params[$column] = $value;
        }
        $this->connection->executeStatement('UPDATE book SET '.implode(', ', $assignments).' WHERE id = :id', $params);
    }

    public function insertPurchase(int $customerId, int $totalCents): int
    {
        return (int) $this->connection->fetchOne(
            'INSERT INTO purchase (customer_id, total_cents) VALUES (:customerId, :totalCents) RETURNING id',
            ['customerId' => $customerId, 'totalCents' => $totalCents],
        );
    }

    public function insertPurchaseLine(int $purchaseId, int $bookId, int $quantity, int $unitPriceCents): void
    {
        $this->connection->executeStatement(
            'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents)'
            .' VALUES (:purchaseId, :bookId, :quantity, :unitPriceCents)',
            ['purchaseId' => $purchaseId, 'bookId' => $bookId, 'quantity' => $quantity, 'unitPriceCents' => $unitPriceCents],
        );
    }

    /**
     * @return list<array{book_id: int|string, title: string, quantity: int|string, revenue_cents: int|string}>
     */
    public function salesBetween(\DateTimeImmutable $fromInclusive, \DateTimeImmutable $toExclusive): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT b.id AS book_id, b.title, SUM(l.quantity) AS quantity, SUM(l.quantity * l.unit_price_cents) AS revenue_cents'
            .' FROM purchase_line l'
            .' JOIN purchase p ON p.id = l.purchase_id'
            .' JOIN book b ON b.id = l.book_id'
            .' WHERE p.created_at >= :from AND p.created_at < :to'
            .' GROUP BY b.id, b.title'
            .' ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            ['from' => $fromInclusive->format(\DateTimeInterface::ATOM), 'to' => $toExclusive->format(\DateTimeInterface::ATOM)],
        );
    }

    // PostgreSQL answers booleans as "t" or "f" text, depending on the driver.
    public static function isPublished(mixed $value): bool
    {
        return true === $value || 't' === $value || 'true' === $value;
    }

    public function transactional(callable $work): mixed
    {
        return $this->connection->transactional($work);
    }

    /**
     * @param array{title: ?string, author: ?string, minPrice: ?int, maxPrice: ?int, publishedAfter: ?string, publishedBefore: ?string, inStock: ?bool} $filters
     *
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function bookConditions(array $filters): array
    {
        $where = ['b.published = TRUE'];
        $params = [];

        if (null !== $filters['title']) {
            $where[] = 'b.title ILIKE :title';
            $params['title'] = '%'.addcslashes($filters['title'], '%_\\').'%';
        }
        if (null !== $filters['author']) {
            $where[] = 'a.name = :author';
            $params['author'] = $filters['author'];
        }
        if (null !== $filters['minPrice']) {
            $where[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = $filters['minPrice'];
        }
        if (null !== $filters['maxPrice']) {
            $where[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = $filters['maxPrice'];
        }
        if (null !== $filters['publishedAfter']) {
            $where[] = 'b.published_at >= CAST(:publishedAfter AS date)';
            $params['publishedAfter'] = $filters['publishedAfter'];
        }
        if (null !== $filters['publishedBefore']) {
            $where[] = 'b.published_at <= CAST(:publishedBefore AS date)';
            $params['publishedBefore'] = $filters['publishedBefore'];
        }
        if (null !== $filters['inStock']) {
            $where[] = $filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [$where, $params];
    }
}
