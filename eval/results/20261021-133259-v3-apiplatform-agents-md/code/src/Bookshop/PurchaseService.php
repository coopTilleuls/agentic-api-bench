<?php

namespace App\Bookshop;

use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Doctrine\DBAL\Connection;

/**
 * Buys books: checks stock, takes the payment, then stores the purchase and decreases stock,
 * all in one transaction so that a failure leaves nothing behind.
 */
final class PurchaseService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BookshopRepository $repository,
        private readonly PaymentGateway $payments,
    ) {
    }

    /**
     * @param list<array{bookId: int, quantity: int}> $lines already validated for shape
     *
     * @return int the id of the stored purchase
     */
    public function buy(int $customerId, array $lines): int
    {
        $quantities = [];
        foreach ($lines as $line) {
            $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
        }
        ksort($quantities);

        $this->connection->beginTransaction();
        try {
            $books = $this->repository->lockBooks(array_keys($quantities));

            $unavailable = [];
            foreach ($quantities as $bookId => $quantity) {
                if (!isset($books[$bookId]) || !$books[$bookId]['published']) {
                    $unavailable[] = JsonApi::error(422, 'Unavailable book', "Book $bookId is not available.", '/data/attributes/lines');
                }
            }
            if ([] !== $unavailable) {
                throw new JsonApiException(422, $unavailable);
            }

            $shortages = [];
            foreach ($quantities as $bookId => $quantity) {
                $stock = (int) $books[$bookId]['stock'];
                if ($stock < $quantity) {
                    $shortages[] = JsonApi::error(409, 'Insufficient stock', sprintf('Book %d has %d copies in stock, %d requested.', $bookId, $stock, $quantity));
                }
            }
            if ([] !== $shortages) {
                throw new JsonApiException(409, $shortages);
            }

            $total = 0;
            foreach ($quantities as $bookId => $quantity) {
                $total += $quantity * (int) $books[$bookId]['price_cents'];
            }

            if (!$this->payments->charge($customerId, $total)) {
                throw JsonApiException::one(402, 'Payment declined', 'The payment was declined.');
            }

            $purchaseId = (int) $this->connection->fetchOne(
                'INSERT INTO purchase (customer_id, total_cents) VALUES (?, ?) RETURNING id',
                [$customerId, $total],
            );
            foreach ($lines as $line) {
                $this->connection->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchaseId, $line['bookId'], $line['quantity'], (int) $books[$line['bookId']]['price_cents']],
                );
            }
            foreach ($quantities as $bookId => $quantity) {
                $this->connection->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            $this->connection->commit();

            return $purchaseId;
        } catch (\Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }
}
