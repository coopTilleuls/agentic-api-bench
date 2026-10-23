<?php

namespace App\Controller;

use App\JsonApi\ApiException;
use App\JsonApi\JsonApi;
use App\JsonApi\Serializer;
use App\Payment\PaymentGateway;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PurchaseController
{
    private const MAX_INT = 2147483647;

    private const SORT_FIELDS = [
        'createdAt' => 'p.created_at',
        'totalCents' => 'p.total_cents',
    ];

    public function __construct(private readonly Connection $db, private readonly PaymentGateway $payments)
    {
    }

    #[Route('/purchases', name: 'purchases_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $customerId = JsonApi::customerId($request, $this->db);
        JsonApi::includes($request, []);

        $order = JsonApi::sortOrder($request, self::SORT_FIELDS);
        $order = $order ?: ['p.created_at DESC'];
        $order[] = 'p.id DESC';

        $page = JsonApi::fetchPage(
            $this->db,
            $request,
            'p.id, p.created_at, p.total_cents',
            'FROM purchase p WHERE p.customer_id = :customer',
            ['customer' => $customerId],
            implode(', ', $order),
        );

        $lines = $this->linesByPurchase(array_column($page['rows'], 'id'));
        $data = array_map(
            static fn (array $purchase): array => Serializer::purchase($purchase, $lines[(int) $purchase['id']] ?? []),
            $page['rows'],
        );

        return JsonApi::listDocument($request, $page, $data);
    }

    #[Route('/purchases/{id}', name: 'purchases_show', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $customerId = JsonApi::customerId($request, $this->db);
        JsonApi::includes($request, []);

        $purchaseId = JsonApi::id($id);
        $purchase = null === $purchaseId ? false : $this->db->fetchAssociative(
            'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.id = :id AND p.customer_id = :customer',
            ['id' => $purchaseId, 'customer' => $customerId],
        );
        if (false === $purchase) {
            throw ApiException::of(404, "Purchase $id not found.");
        }

        $lines = $this->linesByPurchase([$purchase['id']]);

        return JsonApi::document(['data' => Serializer::purchase($purchase, $lines[(int) $purchase['id']] ?? [])]);
    }

    #[Route('/purchases', name: 'purchases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customerId = JsonApi::customerId($request, $this->db);
        JsonApi::requireMediaType($request);
        $data = JsonApi::body($request);
        if (isset($data['type']) && 'purchases' !== $data['type']) {
            throw ApiException::of(409, 'The data type must be purchases.', '/data/type');
        }

        $lines = $this->parseLines($data);

        $this->db->beginTransaction();
        try {
            $purchase = $this->place($customerId, $lines);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return JsonApi::document(
            ['data' => Serializer::purchase($purchase['row'], $purchase['lines'])],
            201,
            ['Location' => '/purchases/'.$purchase['row']['id']],
        );
    }

    /**
     * Checks the request lines; every problem is reported, nothing is read from the database yet.
     *
     * @return list<array{bookId: int, quantity: int}>
     */
    private function parseLines(array $data): array
    {
        $attributes = $data['attributes'] ?? [];
        $lines = is_array($attributes) ? ($attributes['lines'] ?? null) : null;
        if (!is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw new ApiException(422, [JsonApi::error(422, 'The lines must be a non-empty list of purchase lines.', '/data/attributes/lines')]);
        }

        $parsed = [];
        $errors = [];
        foreach ($lines as $index => $line) {
            $pointer = "/data/attributes/lines/$index";
            if (!is_array($line) || array_is_list($line)) {
                $errors[] = JsonApi::error(422, 'Each line must be an object with bookId and quantity.', $pointer);
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $validBook = is_int($bookId) && $bookId >= 1 && $bookId <= self::MAX_INT;
            $validQuantity = is_int($quantity) && $quantity >= 1 && $quantity <= self::MAX_INT;
            if (!$validBook) {
                $errors[] = JsonApi::error(422, 'The bookId must be the integer id of a published book.', "$pointer/bookId");
            }
            if (!$validQuantity) {
                $errors[] = JsonApi::error(422, 'The quantity must be an integer of at least 1.', "$pointer/quantity");
            }
            if ($validBook && $validQuantity) {
                $parsed[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }
        if ($errors) {
            throw new ApiException(422, $errors);
        }

        return $parsed;
    }

    /**
     * Locks the books, checks them and the stock, takes the payment and stores the purchase.
     * Runs inside a transaction: any exception rolls everything back.
     *
     * @param list<array{bookId: int, quantity: int}> $lines
     *
     * @return array{row: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function place(int $customerId, array $lines): array
    {
        $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
        sort($bookIds);

        $books = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, title, price_cents, stock FROM book
             WHERE published = true AND id IN ('.JsonApi::inList($bookIds).')
             ORDER BY id FOR UPDATE',
        );
        foreach ($rows as $book) {
            $books[(int) $book['id']] = $book;
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            if (!isset($books[$line['bookId']])) {
                $errors[] = JsonApi::error(422, "Book {$line['bookId']} is not available.", "/data/attributes/lines/$index/bookId");
            }
        }
        if ($errors) {
            throw new ApiException(422, $errors);
        }

        $wanted = [];
        foreach ($lines as $line) {
            $wanted[$line['bookId']] = ($wanted[$line['bookId']] ?? 0) + $line['quantity'];
        }
        foreach ($wanted as $bookId => $quantity) {
            $stock = (int) $books[$bookId]['stock'];
            if ($quantity > $stock) {
                throw ApiException::of(409, "Not enough stock for book $bookId: $quantity requested, $stock available.");
            }
        }

        $total = 0;
        $purchasedLines = [];
        foreach ($lines as $line) {
            $book = $books[$line['bookId']];
            $unitPrice = (int) $book['price_cents'];
            $total += $unitPrice * $line['quantity'];
            $purchasedLines[] = Serializer::line([
                'book_id' => $line['bookId'],
                'title' => $book['title'],
                'quantity' => $line['quantity'],
                'unit_price_cents' => $unitPrice,
            ]);
        }

        try {
            $payment = $this->payments->charge($customerId, $total);
        } catch (\RuntimeException) {
            throw ApiException::of(502, 'The payment service failed.');
        }
        if ('declined' === $payment) {
            throw ApiException::of(402, 'The payment was declined.');
        }

        $purchase = $this->db->fetchAssociative(
            'INSERT INTO purchase (customer_id, total_cents) VALUES (:customer, :total) RETURNING id, created_at, total_cents',
            ['customer' => $customerId, 'total' => $total],
        );
        foreach ($purchasedLines as $line) {
            $this->db->executeStatement(
                'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (:purchase, :book, :quantity, :price)',
                ['purchase' => $purchase['id'], 'book' => $line['bookId'], 'quantity' => $line['quantity'], 'price' => $line['unitPriceCents']],
            );
        }
        foreach ($wanted as $bookId => $quantity) {
            $this->db->executeStatement('UPDATE book SET stock = stock - :quantity WHERE id = :id', ['quantity' => $quantity, 'id' => $bookId]);
        }

        return ['row' => $purchase, 'lines' => $purchasedLines];
    }

    /**
     * @param list<int|string> $purchaseIds
     *
     * @return array<int, list<array<string, mixed>>> lines by purchase id
     */
    private function linesByPurchase(array $purchaseIds): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN ('.JsonApi::inList($purchaseIds).')
             ORDER BY pl.id',
        );

        $lines = [];
        foreach ($rows as $row) {
            $lines[(int) $row['purchase_id']][] = Serializer::line($row);
        }

        return $lines;
    }
}
