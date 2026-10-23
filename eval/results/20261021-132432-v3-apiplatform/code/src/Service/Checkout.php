<?php

namespace App\Service;

use App\Http\ApiException;
use App\JsonApi\Query;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buys books: checks stock, takes the payment, then stores the purchase and its lines.
 */
final class Checkout
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentGateway $payments,
    ) {
    }

    /**
     * @param list<array{bookId: int, quantity: int}> $lines validated lines, see validate()
     *
     * @return array{id: int, created_at: string, total_cents: int, lines: list<array<string, mixed>>}
     */
    public function buy(int $customerId, array $lines): array
    {
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
        }
        ksort($quantities);
        $bookIds = array_keys($quantities);

        $this->db->beginTransaction();
        try {
            // Locked in id order, so that concurrent purchases can not overbook a book.
            $books = [];
            foreach ($this->db->fetchAllAssociative(
                'SELECT id, title, price_cents, stock, published FROM book WHERE id IN (?) ORDER BY id FOR UPDATE',
                [$bookIds],
                [ArrayParameterType::INTEGER],
            ) as $row) {
                $books[(int) $row['id']] = $row;
            }

            $errors = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || !$book['published']) {
                    $errors[] = [
                        'status' => '422',
                        'title' => Response::$statusTexts[422],
                        'detail' => 'Book '.$line['bookId'].' is not available.',
                        'source' => ['pointer' => '/data/attributes/lines/'.$index.'/bookId'],
                    ];
                }
            }
            if ([] !== $errors) {
                throw new ApiException(422, $errors);
            }

            foreach ($quantities as $bookId => $quantity) {
                if ($quantity > (int) $books[$bookId]['stock']) {
                    throw ApiException::error(409, 'Not enough stock for book '.$bookId.'.');
                }
            }

            $total = 0;
            $purchaseLines = [];
            foreach ($lines as $line) {
                $book = $books[$line['bookId']];
                $unitPrice = (int) $book['price_cents'];
                $total += $unitPrice * $line['quantity'];
                $purchaseLines[] = [
                    'book_id' => $line['bookId'],
                    'title' => $book['title'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $unitPrice,
                ];
            }
            if ($total > Query::MAX_INT) {
                throw ApiException::error(422, 'The total is too large.', pointer: '/data/attributes/lines');
            }

            if (!$this->payments->pay($customerId, $total)) {
                throw ApiException::error(402, 'The payment was declined.');
            }

            $id = (int) $this->db->fetchOne(
                "INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (?, now(), ?) RETURNING id",
                [$customerId, $total],
            );
            $createdAt = (string) $this->db->fetchOne('SELECT created_at FROM purchase WHERE id = ?', [$id]);
            foreach ($purchaseLines as $line) {
                $this->db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$id, $line['book_id'], $line['quantity'], $line['unit_price_cents']],
                );
            }
            foreach ($quantities as $bookId => $quantity) {
                $this->db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return ['id' => $id, 'created_at' => $createdAt, 'total_cents' => $total, 'lines' => $purchaseLines];
    }
}
