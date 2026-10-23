<?php

namespace App\Sales;

use App\Http\ApiException;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Buys books: validates the lines, locks the books, checks stock, takes the payment,
 * then stores the purchase and decreases the stock, all in one transaction.
 */
final class PurchaseService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentGateway $payments,
    ) {
    }

    /**
     * @param list<mixed> $lines the raw lines of the request, each expected as {bookId, quantity}
     *
     * @return int the new purchase id
     *
     * @throws ApiException 422 invalid lines, 409 not enough stock, 402 payment declined, 502 payment service failure
     */
    public function purchase(int $customerId, array $lines): int
    {
        $lines = $this->validateLines($lines);

        $this->db->beginTransaction();
        try {
            $books = $this->lockBooks(array_unique(array_column($lines, 'bookId')));

            $requested = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || !$book['published']) {
                    throw new ApiException(422, "Book {$line['bookId']} is not available.", "/data/attributes/lines/$index/bookId");
                }
                $requested[$line['bookId']] = ($requested[$line['bookId']] ?? 0) + $line['quantity'];
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * (int) $books[$line['bookId']]['price_cents'];
            }
            foreach ($requested as $bookId => $quantity) {
                if ($quantity > $books[$bookId]['stock']) {
                    throw new ApiException(409, \sprintf('Not enough stock for book %d: %d requested, %d available.', $bookId, $quantity, $books[$bookId]['stock']));
                }
            }

            if (!$this->payments->charge($customerId, $total)) {
                throw new ApiException(402, 'The payment was declined.');
            }

            $purchase = $this->db->fetchAssociative(
                'INSERT INTO purchase (customer_id, total_cents) VALUES (?, ?) RETURNING id',
                [$customerId, $total],
            );
            foreach ($lines as $line) {
                $this->db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchase['id'], $line['bookId'], $line['quantity'], $books[$line['bookId']]['price_cents']],
                );
            }
            foreach ($requested as $bookId => $quantity) {
                $this->db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            $this->db->commit();

            return (int) $purchase['id'];
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw $exception;
        }
    }

    /**
     * @param list<mixed> $lines
     *
     * @return list<array{bookId: int, quantity: int}> the lines, in request order
     */
    private function validateLines(array $lines): array
    {
        if ([] === $lines || !array_is_list($lines)) {
            throw new ApiException(422, 'The lines must be a non-empty list.', '/data/attributes/lines');
        }

        $valid = [];
        foreach ($lines as $index => $line) {
            $pointer = "/data/attributes/lines/$index";
            if (!\is_array($line)) {
                throw new ApiException(422, 'Each line must be an object with bookId and quantity.', $pointer);
            }
            $bookId = $line['bookId'] ?? null;
            if (!\is_int($bookId) || $bookId < 1) {
                throw new ApiException(422, 'The bookId must be an integer.', "$pointer/bookId");
            }
            $quantity = $line['quantity'] ?? null;
            if (!\is_int($quantity) || $quantity < 1) {
                throw new ApiException(422, 'The quantity must be an integer of at least 1.', "$pointer/quantity");
            }
            $valid[] = ['bookId' => $bookId, 'quantity' => $quantity];
        }

        return $valid;
    }

    /**
     * @param array<int, int> $bookIds
     *
     * @return array<int, array<string, mixed>> books keyed by id, locked until the transaction ends
     */
    private function lockBooks(array $bookIds): array
    {
        $bookIds = array_values($bookIds);
        sort($bookIds);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, price_cents, stock, published FROM book WHERE id IN (:ids) ORDER BY id FOR UPDATE',
            ['ids' => $bookIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_column($rows, null, 'id');
    }
}
