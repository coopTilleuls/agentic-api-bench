<?php

namespace App\Bookshop;

use App\JsonApi\JsonApiException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Purchases of the customers, and the purchase of books.
 */
final class Purchases
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentGateway $payments,
    ) {
    }

    /**
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    public function forCustomer(int $customerId, int $number, int $size): array
    {
        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM purchase WHERE customer_id = ?',
            [$customerId],
            [ParameterType::INTEGER],
        );

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$customerId, $size, ($number - 1) * $size],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $customerId, int $id): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$id, $customerId],
            [ParameterType::INTEGER, ParameterType::INTEGER],
        ) ?: null;
    }

    /**
     * The lines of the given purchases, keyed by purchase id.
     *
     * @param list<int> $purchaseIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesOf(array $purchaseIds): array
    {
        if ([] === $purchaseIds) {
            return [];
        }

        $lines = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (?) ORDER BY pl.id',
            [$purchaseIds],
            [ArrayParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $lines[(int) $row['purchase_id']][] = $row;
        }

        return $lines;
    }

    /**
     * Buys the books of the lines. Nothing is stored and no stock changes when it fails.
     *
     * @param list<array{index: int, bookId: int, quantity: int}> $lines
     *
     * @return array{purchase: array<string, mixed>, lines: list<array<string, mixed>>}
     *
     * @throws JsonApiException 402 when the payment is declined, 409 when the stock is insufficient,
     *                          422 when a line references an unknown or unpublished book, 502 when the payment fails
     */
    public function buy(int $customerId, array $lines): array
    {
        return $this->db->transactional(function (Connection $db) use ($customerId, $lines): array {
            $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
            sort($bookIds);

            $books = [];
            foreach ($db->fetchAllAssociative(
                'SELECT id, title, price_cents, stock, published::int AS published FROM book WHERE id IN (?) ORDER BY id FOR UPDATE',
                [$bookIds],
                [ArrayParameterType::INTEGER],
            ) as $row) {
                $books[(int) $row['id']] = $row;
            }

            $errors = [];
            foreach ($lines as $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || 1 !== (int) $book['published']) {
                    $errors["/data/attributes/lines/{$line['index']}/bookId"] = sprintf('Book %d does not exist or is not published.', $line['bookId']);
                }
            }
            if ([] !== $errors) {
                throw JsonApiException::validation($errors);
            }

            $quantities = [];
            foreach ($lines as $line) {
                $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
            }
            foreach ($quantities as $bookId => $quantity) {
                $available = (int) $books[$bookId]['stock'];
                if ($quantity > $available) {
                    throw JsonApiException::conflict(sprintf('Not enough stock for book %d: %d requested, %d available.', $bookId, $quantity, $available));
                }
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * (int) $books[$line['bookId']]['price_cents'];
            }

            try {
                $accepted = $this->payments->charge($customerId, $total);
            } catch (PaymentUnavailable $e) {
                throw JsonApiException::badGateway($e->getMessage());
            }
            if (!$accepted) {
                throw JsonApiException::paymentRequired('The payment was declined.');
            }

            $purchaseId = (int) $db->fetchOne(
                'INSERT INTO purchase (customer_id, total_cents) VALUES (?, ?) RETURNING id',
                [$customerId, $total],
                [ParameterType::INTEGER, ParameterType::INTEGER],
            );

            foreach ($lines as $line) {
                $db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchaseId, $line['bookId'], $line['quantity'], (int) $books[$line['bookId']]['price_cents']],
                    [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
                );
            }

            foreach ($quantities as $bookId => $quantity) {
                $db->executeStatement(
                    'UPDATE book SET stock = stock - ? WHERE id = ?',
                    [$quantity, $bookId],
                    [ParameterType::INTEGER, ParameterType::INTEGER],
                );
            }

            $purchase = $this->find($customerId, $purchaseId);

            return ['purchase' => $purchase, 'lines' => $this->linesOf([$purchaseId])[$purchaseId] ?? []];
        });
    }
}
