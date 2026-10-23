<?php

namespace App\Bookshop;

use App\JsonApi\JsonApiException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * The purchases of the customers: reading their own purchases and buying books.
 */
final class Purchases
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentGateway $payment,
    ) {
    }

    /**
     * The customer identified by the Customer-Id header, 401 when missing, malformed or unknown.
     */
    public function identify(Request $request): int
    {
        $raw = $request->headers->get('Customer-Id');
        $id = null === $raw || !preg_match('/^[1-9]\d{0,17}$/', $raw) ? null : (int) $raw;

        if (null === $id || false === $this->db->fetchOne('SELECT 1 FROM customer WHERE id = :id', ['id' => $id])) {
            throw JsonApiException::single(401, 'Unauthorized', 'A known Customer-Id header is required.');
        }

        return $id;
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int}
     */
    public function listFor(int $customerId, int $offset, int $size): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = :c', ['c' => $customerId]);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = :c
             ORDER BY created_at DESC, id DESC' . sprintf(' LIMIT %d OFFSET %d', $size, $offset),
            ['c' => $customerId],
        );

        return ['data' => $this->presentAll($rows), 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null the purchase resource object, null when it is not this customer's
     */
    public function findFor(int $customerId, int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = :id AND customer_id = :c',
            ['id' => $id, 'c' => $customerId],
        );

        return false === $row ? null : $this->presentAll([$row])[0];
    }

    /**
     * Validates the lines of a purchase request: 422 on the first invalid shape.
     *
     * @return list<array{bookId: int, quantity: int}>
     */
    public static function parseLines(mixed $attributes): array
    {
        $lines = \is_array($attributes) ? ($attributes['lines'] ?? null) : null;
        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw JsonApiException::single(422, 'Unprocessable Entity', 'At least one line is required.', '/data/attributes/lines');
        }

        $parsed = [];
        foreach ($lines as $index => $line) {
            $base = sprintf('/data/attributes/lines/%d', $index);
            if (!\is_array($line)) {
                throw JsonApiException::single(422, 'Unprocessable Entity', 'Each line must be an object.', $base);
            }
            if (!\is_int($line['bookId'] ?? null)) {
                throw JsonApiException::single(422, 'Unprocessable Entity', 'The bookId must be an integer.', $base . '/bookId');
            }
            if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                throw JsonApiException::single(422, 'Unprocessable Entity', 'The quantity must be an integer of at least 1.', $base . '/quantity');
            }
            $parsed[] = ['bookId' => $line['bookId'], 'quantity' => $line['quantity']];
        }

        return $parsed;
    }

    /**
     * Buys the lines in one transaction: the books are locked, their stock is checked and decreased,
     * the payment is taken, and the purchase is stored. Any failure rolls everything back.
     *
     * @param list<array{bookId: int, quantity: int}> $lines
     * @return array<string, mixed> the purchase resource object
     */
    public function buy(int $customerId, array $lines): array
    {
        $this->db->beginTransaction();

        try {
            $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
            $books = [];
            foreach ($this->db->fetchAllAssociative(
                'SELECT id, price_cents, stock, (published)::int AS published FROM book WHERE id IN (:ids) ORDER BY id FOR UPDATE',
                ['ids' => $bookIds],
                ['ids' => ArrayParameterType::INTEGER],
            ) as $row) {
                $books[(int) $row['id']] = $row;
            }

            $wanted = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || 1 !== (int) $book['published']) {
                    throw JsonApiException::single(422, 'Unprocessable Entity', sprintf('Book %d is not available.', $line['bookId']), sprintf('/data/attributes/lines/%d/bookId', $index));
                }
                $wanted[$line['bookId']] = ($wanted[$line['bookId']] ?? 0) + $line['quantity'];
            }

            foreach ($wanted as $bookId => $quantity) {
                if ((int) $books[$bookId]['stock'] < $quantity) {
                    throw JsonApiException::single(409, 'Conflict', sprintf('Not enough copies of book %d in stock.', $bookId));
                }
            }

            $totalCents = 0;
            foreach ($lines as $line) {
                $totalCents += $line['quantity'] * (int) $books[$line['bookId']]['price_cents'];
            }

            try {
                $answer = $this->payment->charge($customerId, $totalCents);
            } catch (\RuntimeException) {
                throw JsonApiException::single(502, 'Bad Gateway', 'The payment service is unavailable.');
            }
            if (PaymentGateway::DECLINED === $answer) {
                throw JsonApiException::single(402, 'Payment Required', 'The payment was declined.');
            }

            foreach ($wanted as $bookId => $quantity) {
                $this->db->executeStatement(
                    'UPDATE book SET stock = stock - :q WHERE id = :id AND stock >= :q',
                    ['q' => $quantity, 'id' => $bookId],
                );
            }

            $purchase = $this->db->fetchAssociative(
                'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (:c, now(), :total) RETURNING id',
                ['c' => $customerId, 'total' => $totalCents],
            );
            $purchaseId = (int) $purchase['id'];

            foreach ($lines as $line) {
                $this->db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (:p, :b, :q, :u)',
                    [
                        'p' => $purchaseId,
                        'b' => $line['bookId'],
                        'q' => $line['quantity'],
                        'u' => (int) $books[$line['bookId']]['price_cents'],
                    ],
                );
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw $exception;
        }

        return $this->findFor($customerId, $purchaseId) ?? throw new \LogicException('The purchase was not stored.');
    }

    /**
     * @param list<array<string, mixed>> $rows purchase rows
     * @return list<array<string, mixed>>
     */
    private function presentAll(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $linesByPurchase = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (:ids) ORDER BY pl.id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        ) as $line) {
            $linesByPurchase[(int) $line['purchase_id']][] = [
                'bookId' => (int) $line['book_id'],
                'title' => $line['title'],
                'quantity' => (int) $line['quantity'],
                'unitPriceCents' => (int) $line['unit_price_cents'],
            ];
        }

        return array_map(function (array $row) use ($linesByPurchase): array {
            $id = (string) $row['id'];
            $createdAt = (new \DateTimeImmutable((string) $row['created_at']))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');

            return [
                'type' => 'purchases',
                'id' => $id,
                'attributes' => [
                    'createdAt' => $createdAt,
                    'totalCents' => (int) $row['total_cents'],
                    'lines' => $linesByPurchase[(int) $id] ?? [],
                ],
                'links' => ['self' => '/purchases/' . $id],
            ];
        }, $rows);
    }
}
