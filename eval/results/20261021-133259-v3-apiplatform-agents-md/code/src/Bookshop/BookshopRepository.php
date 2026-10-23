<?php

namespace App\Bookshop;

use App\JsonApi\Page;
use Doctrine\DBAL\Connection;

/**
 * All reads and the stock update done outside purchases.
 */
final class BookshopRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<string>         $order   ORDER BY terms without the id tie-breaker
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listBooks(array $filters, array $order, Page $page): array
    {
        $where = ['b.published = true'];
        $params = [];

        if (null !== $filters['title']) {
            $where[] = 'b.title ILIKE ?';
            $params[] = '%'.addcslashes($filters['title'], '%_\\').'%';
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

        $from = ' FROM book b JOIN author a ON a.id = b.author_id WHERE '.implode(' AND ', $where);
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*)'.$from, $params);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock'.$from
            .' ORDER BY '.implode(', ', [...$order, 'b.id ASC'])
            .' LIMIT '.$page->size.' OFFSET '.$page->offset(),
            $params,
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBook(int $id, bool $publishedOnly): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, title, author_id, price_cents, published_at, stock, published FROM book WHERE id = ?'
            .($publishedOnly ? ' AND published = true' : ''),
            [$id],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, int|string> $changes column => new value
     */
    public function updateBook(int $id, array $changes): void
    {
        $assignments = [];
        $params = [];
        foreach ($changes as $column => $value) {
            $assignments[] = $column.' = ?';
            $params[] = $value;
        }
        $params[] = $id;

        $this->connection->executeStatement('UPDATE book SET '.implode(', ', $assignments).' WHERE id = ?', $params);
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

        return $this->connection->fetchAllAssociative(
            'SELECT id, name FROM author WHERE id IN ('.self::placeholders($ids).') ORDER BY id',
            $ids,
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function listAuthors(Page $page): array
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM author');
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, name FROM author ORDER BY id LIMIT '.$page->size.' OFFSET '.$page->offset(),
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAuthor(int $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, name FROM author WHERE id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * Published books of the given authors, in id order.
     *
     * @param list<int> $authorIds
     *
     * @return list<array<string, mixed>>
     */
    public function publishedBooksByAuthors(array $authorIds): array
    {
        if ([] === $authorIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT id, title, author_id, price_cents, published_at, stock FROM book'
            .' WHERE published = true AND author_id IN ('.self::placeholders($authorIds).') ORDER BY id',
            $authorIds,
        );
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int> the ids among $ids that are published books
     */
    public function publishedBookIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return array_map(
            'intval',
            $this->connection->fetchFirstColumn(
                'SELECT id FROM book WHERE published = true AND id IN ('.self::placeholders($ids).')',
                $ids,
            ),
        );
    }

    /**
     * Locks the given books until the end of the transaction, keyed by id.
     *
     * @param list<int> $ids
     *
     * @return array<int, array<string, mixed>>
     */
    public function lockBooks(array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, price_cents, stock, published FROM book WHERE id IN ('.self::placeholders($ids).') ORDER BY id FOR UPDATE',
            $ids,
        );

        return array_column($rows, null, 'id');
    }

    public function customerExists(int $id): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$id]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function purchasesOf(int $customerId, Page $page): array
    {
        $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ?'
            .' ORDER BY created_at DESC, id DESC LIMIT '.$page->size.' OFFSET '.$page->offset(),
            [$customerId],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchase(int $id, int $customerId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$id, $customerId],
        );

        return false === $row ? null : $row;
    }

    /**
     * Lines of the given purchases, in line id order.
     *
     * @param list<int> $purchaseIds
     *
     * @return list<array<string, mixed>>
     */
    public function purchaseLines(array $purchaseIds): array
    {
        if ([] === $purchaseIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents'
            .' FROM purchase_line pl JOIN book b ON b.id = pl.book_id'
            .' WHERE pl.purchase_id IN ('.self::placeholders($purchaseIds).') ORDER BY pl.id',
            $purchaseIds,
        );
    }

    /**
     * Books sold with a purchase created in [$from, $toExclusive), one row per book.
     *
     * @return list<array<string, mixed>>
     */
    public function salesBetween(string $from, string $toExclusive): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents'
            .' FROM purchase_line pl'
            .' JOIN purchase p ON p.id = pl.purchase_id'
            .' JOIN book b ON b.id = pl.book_id'
            .' WHERE p.created_at >= ? AND p.created_at < ?'
            .' GROUP BY b.id, b.title'
            .' ORDER BY revenue_cents DESC, b.title COLLATE "C" ASC, b.id ASC',
            [$from, $toExclusive],
        );
    }

    /**
     * @param list<int|string> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
