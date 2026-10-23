<?php

namespace App\Service;

use App\JsonApi\ApiException;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\Connection;

/**
 * Buys books: checks stock, takes the payment and records the purchase, all in one transaction.
 */
final class PurchaseService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentGateway $payments,
    ) {
    }

    /**
     * @param list<array{bookId: int, quantity: int}> $requested already shape-checked lines
     *
     * @return array{id: int, created_at: string, total_cents: int, lines: list<array<string, mixed>>}
     *
     * @throws ApiException 422, 409, 402 or 502
     */
    public function buy(int $customerId, array $requested): array
    {
        $this->db->beginTransaction();
        try {
            $bookIds = array_values(array_unique(array_map(static fn (array $line) => $line['bookId'], $requested)));
            $books = [];
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, title, price_cents, stock FROM book
                 WHERE published = true AND id IN ('.implode(',', array_fill(0, \count($bookIds), '?')).')
                 ORDER BY id FOR UPDATE',
                $bookIds,
            );
            foreach ($rows as $row) {
                $books[(int) $row['id']] = $row;
            }

            $errors = [];
            foreach ($requested as $index => $line) {
                if (!isset($books[$line['bookId']])) {
                    $errors[] = ApiException::error(422, 'The book is not available for purchase.', [
                        'pointer' => "/data/attributes/lines/$index/bookId",
                    ]);
                }
            }
            if ([] !== $errors) {
                throw new ApiException($errors);
            }

            $wanted = [];
            foreach ($requested as $line) {
                $wanted[$line['bookId']] = ($wanted[$line['bookId']] ?? 0) + $line['quantity'];
            }
            foreach ($wanted as $bookId => $quantity) {
                if ($quantity > (int) $books[$bookId]['stock']) {
                    throw ApiException::status(409, sprintf('Not enough stock for book %d.', $bookId));
                }
            }

            $lines = [];
            $total = 0;
            foreach ($requested as $line) {
                $unit = (int) $books[$line['bookId']]['price_cents'];
                $total += $unit * $line['quantity'];
                $lines[] = [
                    'book_id' => $line['bookId'],
                    'title' => $books[$line['bookId']]['title'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $unit,
                ];
            }

            $this->payments->charge($customerId, $total);

            $purchase = $this->db->fetchAssociative(
                'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (?, now(), ?) RETURNING id, created_at, total_cents',
                [$customerId, $total],
            );
            foreach ($lines as $line) {
                $this->db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchase['id'], $line['book_id'], $line['quantity'], $line['unit_price_cents']],
                );
            }
            foreach ($wanted as $bookId => $quantity) {
                $this->db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return [
            'id' => (int) $purchase['id'],
            'created_at' => (string) $purchase['created_at'],
            'total_cents' => $total,
            'lines' => $lines,
        ];
    }
}
