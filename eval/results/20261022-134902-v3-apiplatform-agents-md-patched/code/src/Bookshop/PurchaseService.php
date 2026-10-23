<?php

namespace App\Bookshop;

use App\JsonApi\JsonApiException;

/**
 * Buys books for a customer: validates the lines, checks stock, takes the payment, stores the purchase.
 */
final class PurchaseService
{
    private const MAX_INT = 2147483647;

    public function __construct(
        private readonly Store $store,
        private readonly PaymentGateway $payment,
    ) {
    }

    /**
     * @param list<mixed> $lines the raw lines of the request
     * @return int the id of the stored purchase
     */
    public function buy(int $customerId, array $lines): int
    {
        $requested = self::validateLines($lines);
        $connection = $this->store->connection();

        $connection->beginTransaction();
        try {
            $bookIds = array_values(array_unique(array_column($requested, 'bookId')));
            sort($bookIds);

            $books = [];
            foreach ($connection->fetchAllAssociative(
                'SELECT id, price_cents, stock, published FROM book WHERE id IN (:ids) ORDER BY id FOR UPDATE',
                ['ids' => $bookIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
            ) as $row) {
                $books[(int) $row['id']] = $row;
            }

            $quantityByBook = [];
            $total = 0;
            foreach ($requested as $index => $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || !$book['published']) {
                    throw JsonApiException::create(422, 'Invalid line', sprintf('Book %d is not available.', $line['bookId']), sprintf('/data/attributes/lines/%d/bookId', $index));
                }
                $quantityByBook[$line['bookId']] = ($quantityByBook[$line['bookId']] ?? 0) + $line['quantity'];
                $total += $line['quantity'] * (int) $book['price_cents'];
            }

            foreach ($quantityByBook as $bookId => $quantity) {
                if ($quantity > (int) $books[$bookId]['stock']) {
                    throw JsonApiException::create(409, 'Insufficient stock', sprintf('Not enough copies of book %d in stock.', $bookId));
                }
            }

            $this->payment->charge($customerId, $total);

            $purchaseId = (int) $connection->fetchOne(
                'INSERT INTO purchase (customer_id, total_cents) VALUES (:customer, :total) RETURNING id',
                ['customer' => $customerId, 'total' => $total],
            );
            foreach ($requested as $line) {
                $unitPrice = (int) $books[$line['bookId']]['price_cents'];
                $connection->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (:purchase, :book, :quantity, :price)',
                    ['purchase' => $purchaseId, 'book' => $line['bookId'], 'quantity' => $line['quantity'], 'price' => $unitPrice],
                );
            }
            foreach ($quantityByBook as $bookId => $quantity) {
                $connection->executeStatement('UPDATE book SET stock = stock - :quantity WHERE id = :id', ['quantity' => $quantity, 'id' => $bookId]);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return $purchaseId;
    }

    /**
     * @param list<mixed> $lines
     * @return list<array{bookId: int, quantity: int}>
     */
    private static function validateLines(array $lines): array
    {
        if ([] === $lines) {
            throw JsonApiException::create(422, 'Invalid lines', 'At least one line is required.', '/data/attributes/lines');
        }

        $validated = [];
        foreach ($lines as $index => $line) {
            $base = sprintf('/data/attributes/lines/%d', $index);
            if (!is_array($line)) {
                throw JsonApiException::create(422, 'Invalid line', 'A line must be an object.', $base);
            }
            if (!is_int($line['bookId'] ?? null) || $line['bookId'] < 1 || $line['bookId'] > self::MAX_INT) {
                throw JsonApiException::create(422, 'Invalid line', 'bookId must be an integer.', $base.'/bookId');
            }
            if (!is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                throw JsonApiException::create(422, 'Invalid line', 'quantity must be an integer of at least 1.', $base.'/quantity');
            }
            $validated[] = ['bookId' => $line['bookId'], 'quantity' => $line['quantity']];
        }

        return $validated;
    }
}
