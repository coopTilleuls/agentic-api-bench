<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Purchase;
use App\JsonApi\CustomerIdentificationListener;
use App\JsonApi\JsonApiException;
use App\JsonApi\RequestDocument;
use App\Payment\PaymentService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Buys books: checks the stock, takes the payment, then stores the purchase and updates the stock.
 *
 * @implements ProcessorInterface<mixed, Purchase>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentService $payment,
        private readonly PurchaseProvider $purchases,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        $request = $context['request'];
        $customerId = (int) $request->attributes->get(CustomerIdentificationListener::ATTRIBUTE);

        $attributes = RequestDocument::attributes(RequestDocument::data($request, 'purchases'));
        $lines = $this->lines($attributes['lines'] ?? null);

        return $this->connection->transactional(function (Connection $connection) use ($customerId, $lines): Purchase {
            $books = $this->lockBooks($lines);

            $total = 0;
            $quantities = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']];
                if (null === $book || !$book['published']) {
                    throw JsonApiException::unprocessable([[
                        'pointer' => sprintf('/data/attributes/lines/%d/bookId', $index),
                        'detail' => 'The book must be a published book.',
                    ]]);
                }

                $quantities[$line['bookId']] = ($quantities[$line['bookId']] ?? 0) + $line['quantity'];
                $total += $line['quantity'] * $book['price_cents'];
            }

            foreach ($quantities as $bookId => $quantity) {
                if ($quantity > $books[$bookId]['stock']) {
                    throw JsonApiException::single(409, 'Conflict', sprintf('Not enough stock for book %d: %d asked, %d available.', $bookId, $quantity, $books[$bookId]['stock']));
                }
            }

            if (!$this->payment->charge($customerId, $total)) {
                throw JsonApiException::single(402, 'Payment Required', 'The payment was declined.');
            }

            $purchase = $connection->fetchAssociative(
                'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (?, now(), ?) RETURNING id, created_at, total_cents',
                [$customerId, $total],
            );

            foreach ($lines as $line) {
                $connection->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchase['id'], $line['bookId'], $line['quantity'], $books[$line['bookId']]['price_cents']],
                );
            }

            foreach ($quantities as $bookId => $quantity) {
                $connection->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            return $this->purchases->withLines([$purchase])[0];
        });
    }

    /**
     * @return list<array{bookId: int, quantity: int}>
     */
    private function lines(mixed $lines): array
    {
        if (!\is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw JsonApiException::unprocessable([['pointer' => '/data/attributes/lines', 'detail' => 'At least one line is required.']]);
        }

        $result = [];
        foreach ($lines as $index => $line) {
            if (!\is_array($line) || array_is_list($line)) {
                throw JsonApiException::unprocessable([['pointer' => sprintf('/data/attributes/lines/%d', $index), 'detail' => 'A line must be an object.']]);
            }
            if (!\is_int($line['bookId'] ?? null)) {
                throw JsonApiException::unprocessable([['pointer' => sprintf('/data/attributes/lines/%d/bookId', $index), 'detail' => 'The bookId must be an integer.']]);
            }
            if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                throw JsonApiException::unprocessable([['pointer' => sprintf('/data/attributes/lines/%d/quantity', $index), 'detail' => 'The quantity must be an integer greater than or equal to 1.']]);
            }

            $result[] = ['bookId' => $line['bookId'], 'quantity' => $line['quantity']];
        }

        return $result;
    }

    /**
     * @param list<array{bookId: int, quantity: int}> $lines
     *
     * @return array<int, array{title: string, price_cents: int, published: bool, stock: int}|null> the books, by id, locked until the end of the transaction
     */
    private function lockBooks(array $lines): array
    {
        $ids = array_values(array_unique(array_column($lines, 'bookId')));
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, price_cents, published, stock FROM book WHERE id IN (?) ORDER BY id FOR UPDATE',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $books = array_fill_keys($ids, null);
        foreach ($rows as $row) {
            $books[(int) $row['id']] = [
                'title' => $row['title'],
                'price_cents' => (int) $row['price_cents'],
                'published' => (bool) $row['published'],
                'stock' => (int) $row['stock'],
            ];
        }

        return $books;
    }
}
