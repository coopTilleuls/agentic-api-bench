<?php

namespace App\Service;

use App\Http\JsonApiException;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Stores a purchase: locks the books, checks their availability and stock, takes the payment,
 * then writes the purchase, its lines and the new stock in one transaction.
 */
final class Checkout
{
    public function __construct(private readonly Connection $db, private readonly PaymentGateway $payments)
    {
    }

    /**
     * @param list<array{index: int, bookId: int, quantity: int}> $lines
     *
     * @return int the id of the new purchase
     */
    public function purchase(int $customerId, array $lines): int
    {
        $this->db->beginTransaction();
        try {
            $purchaseId = $this->run($customerId, $lines);
            $this->db->commit();

            return $purchaseId;
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }
    }

    /**
     * @param list<array{index: int, bookId: int, quantity: int}> $lines
     */
    private function run(int $customerId, array $lines): int
    {
        $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
        sort($bookIds);

        // Lock the books in id order so concurrent purchases cannot deadlock or oversell.
        $books = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT id, price_cents, stock, published FROM book WHERE id IN (:ids) ORDER BY id FOR UPDATE',
            ['ids' => $bookIds],
            ['ids' => ArrayParameterType::INTEGER],
        ) as $row) {
            $books[(int) $row['id']] = $row;
        }

        $violations = [];
        foreach ($lines as $line) {
            $book = $books[$line['bookId']] ?? null;
            if (null === $book || !$book['published']) {
                $violations[] = [
                    'pointer' => '/data/attributes/lines/'.$line['index'].'/bookId',
                    'detail' => \sprintf('Book %d is not available for purchase.', $line['bookId']),
                ];
            }
        }
        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        $needed = [];
        $total = 0;
        foreach ($lines as $line) {
            $needed[$line['bookId']] = ($needed[$line['bookId']] ?? 0) + $line['quantity'];
            $total += $line['quantity'] * (int) $books[$line['bookId']]['price_cents'];
        }
        foreach ($needed as $bookId => $quantity) {
            if ((int) $books[$bookId]['stock'] < $quantity) {
                throw JsonApiException::single(409, 'Insufficient stock', \sprintf('Book %d has not enough copies in stock for the quantity asked.', $bookId));
            }
        }

        $this->payments->charge($customerId, $total);

        $purchaseId = (int) $this->db->fetchOne(
            'INSERT INTO purchase (customer_id, total_cents) VALUES (:customer, :total) RETURNING id',
            ['customer' => $customerId, 'total' => $total],
        );
        foreach ($lines as $line) {
            $this->db->insert('purchase_line', [
                'purchase_id' => $purchaseId,
                'book_id' => $line['bookId'],
                'quantity' => $line['quantity'],
                'unit_price_cents' => (int) $books[$line['bookId']]['price_cents'],
            ]);
        }
        foreach ($needed as $bookId => $quantity) {
            $this->db->executeStatement('UPDATE book SET stock = stock - :quantity WHERE id = :id', ['quantity' => $quantity, 'id' => $bookId]);
        }

        return $purchaseId;
    }
}
