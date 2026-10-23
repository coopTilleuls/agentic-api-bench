<?php

namespace App\Repository;

use App\Http\ApiException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Reads and writes the bookshop tables with SQL; the schema is owned by the database.
 */
final class BookshopRepository
{
    private const BOOK_COLUMNS = 'b.id, b.title, b.author_id, b.price_cents, b.published, b.published_at, b.stock, a.name AS author_name';

    /** Sortable API field => column. */
    private const BOOK_SORT_COLUMNS = ['priceCents' => 'b.price_cents', 'title' => 'b.title', 'publishedAt' => 'b.published_at'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, string|int|bool> $filters
     */
    public function countBooks(array $filters): int
    {
        return (int) $this->publishedBooksQuery($filters)->select('COUNT(*)')->executeQuery()->fetchOne();
    }

    /**
     * @param array<string, string|int|bool> $filters
     * @param list<array{0: string, 1: bool}> $sort
     *
     * @return list<array<string, mixed>>
     */
    public function findBooks(array $filters, array $sort, int $offset, int $limit): array
    {
        $qb = $this->publishedBooksQuery($filters)
            ->select(self::BOOK_COLUMNS)
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        foreach ($sort as [$field, $descending]) {
            $qb->addOrderBy(self::BOOK_SORT_COLUMNS[$field], $descending ? 'DESC' : 'ASC');
        }
        $qb->addOrderBy('b.id', 'ASC');

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBook(int $id, bool $includeUnpublished = false): ?array
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM book b JOIN author a ON a.id = b.author_id WHERE b.id = ?%s', self::BOOK_COLUMNS, $includeUnpublished ? '' : ' AND b.published'),
            [$id],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param array<string, int|string> $changes column => new value
     */
    public function updateBook(int $id, array $changes): void
    {
        $this->connection->update('book', $changes, ['id' => $id]);
    }

    public function countAuthors(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM author');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAuthors(int $offset, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
             FROM author a ORDER BY a.id LIMIT ? OFFSET ?',
            [$limit, $offset],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAuthor(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
             FROM author a WHERE a.id = ?',
            [$id],
        );

        return false === $row ? null : $row;
    }

    /**
     * Published books of the given authors, in id order.
     *
     * @param list<int|string> $authorIds
     *
     * @return list<array<string, mixed>>
     */
    public function publishedBooksOf(array $authorIds): array
    {
        if ([] === $authorIds) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            sprintf('SELECT %s FROM book b JOIN author a ON a.id = b.author_id WHERE b.published AND b.author_id IN (?) ORDER BY b.id', self::BOOK_COLUMNS),
            [$authorIds],
            [ArrayParameterType::INTEGER],
        );
    }

    public function countPurchases(int $customerId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findPurchases(int $customerId, int $offset, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$customerId, $limit, $offset],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return $this->withLines($rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchase(int $customerId, int $id): ?array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? AND id = ?',
            [$customerId, $id],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return $this->withLines($rows)[0] ?? null;
    }

    /**
     * Books sold between two instants (inclusive start, exclusive end), by revenue.
     *
     * @return list<array{title: string, quantity: int, revenueCents: int}>
     */
    public function salesReport(\DateTimeImmutable $from, \DateTimeImmutable $untilExclusive): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            [$from->format('Y-m-d H:i:sP'), $untilExclusive->format('Y-m-d H:i:sP')],
        );

        return array_map(static fn (array $row): array => [
            'title' => $row['title'],
            'quantity' => (int) $row['quantity'],
            'revenueCents' => (int) $row['revenue_cents'],
        ], $rows);
    }

    /**
     * Stores a purchase and decrements the stock of its books in one transaction.
     *
     * The books are locked first so that the stock check and the payment see a stable state; the
     * callable receives the total in cents and must throw to abort the purchase.
     *
     * @param list<array{bookId: int, quantity: int}> $lines
     * @param callable(int): void                     $pay
     *
     * @return int the new purchase id
     */
    public function placePurchase(int $customerId, array $lines, callable $pay): int
    {
        return $this->connection->transactional(function (Connection $connection) use ($customerId, $lines, $pay): int {
            $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
            sort($bookIds);

            $books = [];
            foreach ($connection->fetchAllAssociative(
                'SELECT id, price_cents, stock, published FROM book WHERE id IN (?) ORDER BY id FOR UPDATE',
                [$bookIds],
                [ArrayParameterType::INTEGER],
            ) as $row) {
                $books[(int) $row['id']] = [
                    'priceCents' => (int) $row['price_cents'],
                    'stock' => (int) $row['stock'],
                    'published' => self::bool($row['published']),
                ];
            }

            $errors = [];
            foreach ($lines as $index => $line) {
                if (!isset($books[$line['bookId']]) || !$books[$line['bookId']]['published']) {
                    $errors[] = ApiException::error(422, sprintf('Book %d is not available.', $line['bookId']), ['pointer' => sprintf('/data/attributes/lines/%d/bookId', $index)]);
                }
            }
            if ([] !== $errors) {
                throw new ApiException(422, $errors);
            }

            $quantities = [];
            foreach ($lines as $line) {
                $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
            }
            foreach ($quantities as $bookId => $quantity) {
                if ($books[$bookId]['stock'] < $quantity) {
                    $errors[] = ApiException::error(409, sprintf('Not enough stock for book %d: %d requested, %d available.', $bookId, $quantity, $books[$bookId]['stock']));
                }
            }
            if ([] !== $errors) {
                throw new ApiException(409, $errors);
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * $books[$line['bookId']]['priceCents'];
            }
            $pay($total);

            $purchaseId = (int) $connection->fetchOne(
                'INSERT INTO purchase (customer_id, total_cents) VALUES (?, ?) RETURNING id',
                [$customerId, $total],
            );
            foreach ($lines as $line) {
                $connection->insert('purchase_line', [
                    'purchase_id' => $purchaseId,
                    'book_id' => $line['bookId'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $books[$line['bookId']]['priceCents'],
                ]);
            }
            foreach ($quantities as $bookId => $quantity) {
                $connection->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            return $purchaseId;
        });
    }

    /**
     * @param array<string, string|int|bool> $filters
     */
    private function publishedBooksQuery(array $filters): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder()
            ->from('book', 'b')
            ->innerJoin('b', 'author', 'a', 'a.id = b.author_id')
            ->where('b.published = TRUE');

        if (isset($filters['title'])) {
            $qb->andWhere('b.title ILIKE :title')
                ->setParameter('title', '%'.addcslashes((string) $filters['title'], '%_\\').'%');
        }
        if (isset($filters['author'])) {
            $qb->andWhere('a.name = :author')->setParameter('author', $filters['author']);
        }
        if (isset($filters['minPrice'])) {
            $qb->andWhere('b.price_cents >= :minPrice')->setParameter('minPrice', $filters['minPrice'], ParameterType::INTEGER);
        }
        if (isset($filters['maxPrice'])) {
            $qb->andWhere('b.price_cents <= :maxPrice')->setParameter('maxPrice', $filters['maxPrice'], ParameterType::INTEGER);
        }
        if (isset($filters['publishedAfter'])) {
            $qb->andWhere('b.published_at >= :publishedAfter')->setParameter('publishedAfter', $filters['publishedAfter']);
        }
        if (isset($filters['publishedBefore'])) {
            $qb->andWhere('b.published_at <= :publishedBefore')->setParameter('publishedBefore', $filters['publishedBefore']);
        }
        if (array_key_exists('inStock', $filters)) {
            $qb->andWhere($filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0');
        }

        return $qb;
    }

    /**
     * @param list<array<string, mixed>> $purchases
     *
     * @return list<array<string, mixed>>
     */
    private function withLines(array $purchases): array
    {
        if ([] === $purchases) {
            return [];
        }

        $lines = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (?) ORDER BY pl.id',
            [array_column($purchases, 'id')],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            $lines[(int) $row['purchase_id']][] = [
                'bookId' => (int) $row['book_id'],
                'title' => $row['title'],
                'quantity' => (int) $row['quantity'],
                'unitPriceCents' => (int) $row['unit_price_cents'],
            ];
        }

        return array_map(
            static fn (array $purchase): array => $purchase + ['lines' => $lines[(int) $purchase['id']] ?? []],
            $purchases,
        );
    }

    /**
     * PDO returns PostgreSQL booleans as 't'/'f' strings or as bools, depending on the driver.
     */
    public static function bool(mixed $value): bool
    {
        return true === $value || 't' === $value || '1' === $value || 1 === $value;
    }
}
