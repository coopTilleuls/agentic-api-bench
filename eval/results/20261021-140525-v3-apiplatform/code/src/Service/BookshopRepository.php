<?php

namespace App\Service;

use App\Exception\ApiException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * All the SQL of the bookshop, over the existing tables.
 */
final class BookshopRepository
{
    /** Largest value of a PostgreSQL integer column, ids beyond it cannot exist. */
    private const MAX_ID = 2147483647;

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentClient $payments,
    ) {
    }

    public function customerExists(int $customerId): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$customerId]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findAuthors(int $page, int $size): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
             FROM author a ORDER BY a.id LIMIT ? OFFSET ?',
            [$size, ($page - 1) * $size],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return ['rows' => $rows, 'total' => (int) $this->db->fetchOne('SELECT COUNT(*) FROM author')];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAuthor(int $id): ?array
    {
        if ($id < 1 || $id > self::MAX_ID) {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books
             FROM author a WHERE a.id = ?',
            [$id],
        );

        return false === $row ? null : $row;
    }

    /**
     * The published books of an author, in id order.
     *
     * @return list<array<string, mixed>>
     */
    public function findPublishedBooksOfAuthor(int $authorId): array
    {
        return $this->db->fetchAllAssociative(
            self::BOOK_SELECT . ' WHERE b.published AND b.author_id = ? ORDER BY b.id',
            [$authorId],
        );
    }

    /**
     * @param list<array{field: string, descending: bool}> $sort
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findPublishedBooks(array $filters, array $sort, int $page, int $size): array
    {
        $conditions = ['b.published'];
        $params = [];

        if (null !== $filters['title']) {
            $conditions[] = "b.title ILIKE ? ESCAPE '\\'";
            $params[] = '%' . addcslashes($filters['title'], '\\%_') . '%';
        }
        if (null !== $filters['author']) {
            $conditions[] = 'a.name = ?';
            $params[] = $filters['author'];
        }
        if (null !== $filters['minPrice']) {
            $conditions[] = 'b.price_cents >= ?';
            $params[] = $filters['minPrice'];
        }
        if (null !== $filters['maxPrice']) {
            $conditions[] = 'b.price_cents <= ?';
            $params[] = $filters['maxPrice'];
        }
        if (null !== $filters['publishedAfter']) {
            $conditions[] = 'b.published_at >= ?';
            $params[] = $filters['publishedAfter'];
        }
        if (null !== $filters['publishedBefore']) {
            $conditions[] = 'b.published_at <= ?';
            $params[] = $filters['publishedBefore'];
        }
        if (null !== $filters['inStock']) {
            $conditions[] = $filters['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        $where = ' WHERE ' . implode(' AND ', $conditions);

        $order = [];
        foreach ($sort as $item) {
            $order[] = self::SORT_COLUMNS[$item['field']] . ($item['descending'] ? ' DESC' : ' ASC');
        }
        $order[] = 'b.id ASC';

        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id' . $where,
            $params,
        );

        $rows = $this->db->fetchAllAssociative(
            self::BOOK_SELECT . $where . ' ORDER BY ' . implode(', ', $order) . ' LIMIT ? OFFSET ?',
            [...$params, $size, ($page - 1) * $size],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBook(int $id, bool $publishedOnly = true): ?array
    {
        if ($id < 1 || $id > self::MAX_ID) {
            return null;
        }

        $row = $this->db->fetchAssociative(
            self::BOOK_SELECT . ' WHERE b.id = ?' . ($publishedOnly ? ' AND b.published' : ''),
            [$id],
        );

        return false === $row ? null : $row;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function findPurchases(int $customerId, int $page, int $size): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ?
             ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$customerId, $size, ($page - 1) * $size],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);

        return ['rows' => $this->withLines($rows), 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPurchase(int $customerId, int $id): ?array
    {
        if ($id < 1 || $id > self::MAX_ID) {
            return null;
        }

        $row = $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$id, $customerId],
        );
        if (false === $row) {
            return null;
        }

        return $this->withLines([$row])[0];
    }

    /**
     * @return list<array{title: string, quantity: int, revenueCents: int}>
     */
    public function salesBetween(\DateTimeImmutable $from, \DateTimeImmutable $untilExclusive): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            [$from->format(\DATE_ATOM), $untilExclusive->format(\DATE_ATOM)],
        );
    }

    /**
     * Buys books for a customer: checks the stock and the payment, then stores the purchase and
     * decreases the stock, all in one transaction. Returns the new purchase id.
     *
     * @param list<array{bookId: int, quantity: int}> $lines
     *
     * @throws ApiException 422, 409, 402 or 502
     */
    public function placePurchase(int $customerId, array $lines): int
    {
        return $this->db->transactional(function (Connection $db) use ($customerId, $lines): int {
            $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
            sort($bookIds);

            $books = [];
            foreach ($db->fetchAllAssociative(
                'SELECT id, title, price_cents, stock, published FROM book WHERE id IN (?) ORDER BY id FOR UPDATE',
                [$bookIds],
                [ArrayParameterType::INTEGER],
            ) as $book) {
                $books[(int) $book['id']] = $book;
            }

            $violations = [];
            $asked = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || !$book['published']) {
                    $violations[] = [
                        'pointer' => sprintf('/data/attributes/lines/%d/bookId', $index),
                        'detail' => sprintf('Book %d does not exist or is not published.', $line['bookId']),
                    ];
                    continue;
                }
                $asked[$line['bookId']] = ($asked[$line['bookId']] ?? 0) + $line['quantity'];
            }
            if ([] !== $violations) {
                throw ApiException::validation($violations);
            }

            foreach ($asked as $bookId => $quantity) {
                if ($quantity > (int) $books[$bookId]['stock']) {
                    throw ApiException::single(409, 'Conflict', sprintf('Not enough stock for book %d.', $bookId));
                }
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * (int) $books[$line['bookId']]['price_cents'];
            }

            $this->payments->charge($customerId, $total);

            $purchaseId = (int) $db->fetchOne(
                'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (?, now(), ?) RETURNING id',
                [$customerId, $total],
            );

            foreach ($lines as $line) {
                $book = $books[$line['bookId']];
                $db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchaseId, $line['bookId'], $line['quantity'], $book['price_cents']],
                );
            }
            foreach ($asked as $bookId => $quantity) {
                $db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            return $purchaseId;
        });
    }

    /**
     * Updates the given columns of a book (published or not) and returns the book.
     *
     * @param array<string, int|string> $changes column => value
     *
     * @return array<string, mixed>
     */
    public function updateBook(int $id, array $changes): array
    {
        if ([] !== $changes) {
            $assignments = [];
            $params = [];
            foreach ($changes as $column => $value) {
                $assignments[] = $column . ' = ?';
                $params[] = $value;
            }
            $params[] = $id;
            $this->db->executeStatement(
                'UPDATE book SET ' . implode(', ', $assignments) . ' WHERE id = ?',
                $params,
            );
        }

        return $this->findBook($id, false) ?? throw ApiException::single(404, 'Not Found', 'Book not found.');
    }

    private const BOOK_SELECT = 'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.published,
            a.id AS author_id, a.name AS author_name,
            (SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published) AS author_published_books
        FROM book b JOIN author a ON a.id = b.author_id';

    private const SORT_COLUMNS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

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

        $ids = array_map(static fn (array $purchase): int => (int) $purchase['id'], $purchases);
        $lines = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (?) ORDER BY pl.id',
            [$ids],
            [ArrayParameterType::INTEGER],
        ) as $line) {
            $lines[(int) $line['purchase_id']][] = $line;
        }

        foreach ($purchases as $index => $purchase) {
            $purchases[$index]['lines'] = $lines[(int) $purchase['id']] ?? [];
        }

        return $purchases;
    }
}
