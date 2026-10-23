<?php

namespace App\Controller;

use App\Bookshop\PaymentClient;
use App\Bookshop\PurchaseLines;
use App\JsonApi\Input;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiResponse;
use App\JsonApi\Resources;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseCreateController
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentClient $payment,
        private readonly PurchaseLines $lines,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $customerId = $request->attributes->getInt('customerId');
        $body = Input::body($request);

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw JsonApiException::validation([['/data', 'The document must contain a data object.']]);
        }
        if (($data['type'] ?? null) !== 'purchases') {
            throw JsonApiException::conflict('The resource type must be "purchases".');
        }

        $attributes = $data['attributes'] ?? null;
        if (!is_array($attributes)) {
            throw JsonApiException::validation([['/data/attributes', 'The attributes must be an object.']]);
        }

        $requestLines = $attributes['lines'] ?? null;
        if (!is_array($requestLines) || !array_is_list($requestLines) || [] === $requestLines) {
            throw JsonApiException::validation([['/data/attributes/lines', 'The lines must be a non-empty list.']]);
        }

        [$quantities, $firstLine] = $this->checkLines($requestLines);

        $this->db->beginTransaction();
        try {
            $books = $this->lockBooks(array_keys($quantities));

            $violations = [];
            foreach (array_keys($quantities) as $bookId) {
                if (!isset($books[$bookId]) || 0 === (int) $books[$bookId]['published']) {
                    $violations[] = [sprintf('/data/attributes/lines/%d/bookId', $firstLine[$bookId]), sprintf('Book %d is not available.', $bookId)];
                }
            }
            if ([] !== $violations) {
                throw JsonApiException::validation($violations);
            }

            foreach ($quantities as $bookId => $quantity) {
                if ((int) $books[$bookId]['stock'] < $quantity) {
                    throw JsonApiException::conflict(sprintf('Not enough stock for book %d.', $bookId));
                }
            }

            $total = 0;
            foreach ($requestLines as $line) {
                $total += (int) $books[$line['bookId']]['price_cents'] * $line['quantity'];
            }

            if (!$this->payment->charge($customerId, $total)) {
                throw JsonApiException::paymentDeclined();
            }

            $purchase = $this->db->fetchAssociative(
                'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (?, now(), ?) RETURNING id, created_at, total_cents',
                [$customerId, $total],
            );

            foreach ($requestLines as $line) {
                $this->db->insert('purchase_line', [
                    'purchase_id' => $purchase['id'],
                    'book_id' => $line['bookId'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $books[$line['bookId']]['price_cents'],
                ]);
            }

            foreach ($quantities as $bookId => $quantity) {
                $this->db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            $this->db->commit();
        } catch (\Throwable $exception) {
            $this->db->rollBack();

            throw $exception;
        }

        $lines = $this->lines->grouped([$purchase['id']])[(string) $purchase['id']] ?? [];

        return JsonApiResponse::document(
            ['data' => Resources::purchase($purchase, $lines)],
            201,
            ['Location' => '/purchases/'.$purchase['id']],
        );
    }

    /**
     * Checks the shape of every line and returns the total quantity per book and the first line index of each book.
     *
     * @param list<mixed> $lines
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function checkLines(array $lines): array
    {
        $violations = [];
        $quantities = [];
        $firstLine = [];

        foreach ($lines as $index => $line) {
            $pointer = sprintf('/data/attributes/lines/%d', $index);
            if (!is_array($line) || array_is_list($line)) {
                $violations[] = [$pointer, 'A line must be an object.'];
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $lineOk = true;

            if (!is_int($bookId) || $bookId < 1) {
                $violations[] = [$pointer.'/bookId', 'The bookId must be an integer.'];
                $lineOk = false;
            }
            if (!is_int($quantity) || $quantity < 1) {
                $violations[] = [$pointer.'/quantity', 'The quantity must be an integer of at least 1.'];
                $lineOk = false;
            }

            if ($lineOk) {
                $quantities[$bookId] = ($quantities[$bookId] ?? 0) + $quantity;
                $firstLine[$bookId] ??= $index;
            }
        }

        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        return [$quantities, $firstLine];
    }

    /**
     * Locks the given books until the end of the transaction and returns them by id.
     *
     * @param list<int> $bookIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function lockBooks(array $bookIds): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, price_cents, stock, published::int AS published FROM book WHERE id IN ('.implode(',', $bookIds).') ORDER BY id FOR UPDATE',
        );

        $books = [];
        foreach ($rows as $row) {
            $books[(int) $row['id']] = $row;
        }

        return $books;
    }
}
