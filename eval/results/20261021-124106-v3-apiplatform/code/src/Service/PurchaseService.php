<?php

namespace App\Service;

use App\Http\ApiException;
use Doctrine\DBAL\Connection;

/**
 * Places purchases: stock check, payment and storage in one transaction.
 */
final class PurchaseService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentGateway $payments,
    ) {
    }

    /**
     * @param list<array{bookId: int, quantity: int}> $lines already validated for shape
     *
     * @return int the id of the new purchase
     *
     * @throws ApiException 422 unknown or unpublished book, 409 not enough stock, 402/502 payment
     */
    public function place(int $customerId, array $lines): int
    {
        return $this->db->transactional(function (Connection $db) use ($customerId, $lines): int {
            $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
            sort($bookIds);
            $marks = implode(', ', array_fill(0, count($bookIds), '?'));
            // Locks the books for the rest of the transaction so that stock cannot change under the check.
            $rows = $db->fetchAllAssociative(
                "SELECT id, price_cents, stock, published FROM book WHERE id IN ($marks) ORDER BY id FOR UPDATE",
                $bookIds,
            );
            $books = [];
            foreach ($rows as $row) {
                $books[(int) $row['id']] = $row;
            }

            $errors = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || !$book['published']) {
                    $errors[] = [
                        'status' => '422',
                        'title' => 'Unprocessable Entity',
                        'detail' => "Book {$line['bookId']} is not available.",
                        'source' => ['pointer' => "/data/attributes/lines/$index/bookId"],
                    ];
                }
            }
            if ([] !== $errors) {
                throw new ApiException(422, $errors);
            }

            $wanted = [];
            foreach ($lines as $line) {
                $wanted[$line['bookId']] = ($wanted[$line['bookId']] ?? 0) + $line['quantity'];
            }
            foreach ($wanted as $bookId => $quantity) {
                if ((int) $books[$bookId]['stock'] < $quantity) {
                    throw ApiException::one(409, "Not enough stock for book $bookId.");
                }
            }

            $total = 0;
            $priced = [];
            foreach ($lines as $line) {
                $unit = (int) $books[$line['bookId']]['price_cents'];
                $total += $unit * $line['quantity'];
                $priced[] = ['bookId' => $line['bookId'], 'quantity' => $line['quantity'], 'unitPriceCents' => $unit];
            }

            $this->payments->charge($customerId, $total);

            $purchaseId = (int) $db->fetchOne(
                'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (?, now(), ?) RETURNING id',
                [$customerId, $total],
            );
            foreach ($priced as $line) {
                $db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchaseId, $line['bookId'], $line['quantity'], $line['unitPriceCents']],
                );
                $db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$line['quantity'], $line['bookId']]);
            }

            return $purchaseId;
        });
    }
}
