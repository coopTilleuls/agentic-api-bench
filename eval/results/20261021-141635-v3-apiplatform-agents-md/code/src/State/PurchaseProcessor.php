<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Purchase;
use App\JsonApi\JsonApiException;
use App\JsonApi\RequestBody;
use App\Payment\PaymentClient;
use App\Payment\PaymentFailed;
use App\Repository\BookshopRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Buys books for the customer: checks the stock, takes the payment, then stores the purchase.
 *
 * @implements ProcessorInterface<null, Purchase>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    private const MAX_INT = 2147483647;

    public function __construct(
        private readonly Connection $db,
        private readonly BookshopRepository $repository,
        private readonly PaymentClient $payment,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        $request = $context['request'];
        $customerId = $request->attributes->getInt('customer_id');

        $resource = RequestBody::data($request);
        if (isset($resource['type']) && 'purchases' !== $resource['type']) {
            throw JsonApiException::create(409, 'Conflict', 'The type must be "purchases".');
        }

        $quantities = $this->lines(RequestBody::attributes($resource));

        return $this->db->transactional(function (Connection $db) use ($customerId, $quantities): Purchase {
            // Lock the books in id order, so concurrent purchases cannot oversell.
            $books = $db->executeQuery(
                'SELECT id, title, price_cents, stock, published::int AS published FROM book WHERE id IN (:ids) ORDER BY id FOR UPDATE',
                ['ids' => array_keys($quantities)],
                ['ids' => ArrayParameterType::INTEGER],
            )->fetchAllAssociativeIndexed();

            $errors = [];
            $total = 0;
            $short = [];
            foreach ($quantities as $bookId => $line) {
                $book = $books[$bookId] ?? null;
                if (null === $book || !$book['published']) {
                    $errors[] = JsonApiException::error(422, 'Unprocessable Entity', "The book $bookId is not available.", ['pointer' => $line['pointer']]);
                    continue;
                }
                if ($book['stock'] < $line['quantity']) {
                    $short[] = $bookId;
                }
                $total += $line['quantity'] * $book['price_cents'];
            }
            if ($errors) {
                throw new JsonApiException(422, $errors);
            }
            if ($short) {
                throw JsonApiException::create(409, 'Conflict', 'Not enough stock for the book(s) '.implode(', ', $short).'.');
            }

            $answer = $this->charge($customerId, $total);
            if ('declined' === $answer) {
                throw JsonApiException::create(402, 'Payment Required', 'The payment was declined.');
            }

            $purchase = $db->fetchAssociative(
                'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (?, ?, ?) RETURNING id, created_at, total_cents',
                [$customerId, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM), $total],
            );

            foreach ($quantities as $bookId => $line) {
                $db->executeStatement(
                    'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (?, ?, ?, ?)',
                    [$purchase['id'], $bookId, $line['quantity'], $books[$bookId]['price_cents']],
                );
                $db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$line['quantity'], $bookId]);
            }

            return Mapper::purchase($purchase, $this->repository->linesOf([(int) $purchase['id']]));
        });
    }

    /**
     * Checks the lines of the request and totals the quantity asked for each book.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<int, array{quantity: int, pointer: string}> by book id
     */
    private function lines(array $attributes): array
    {
        $lines = $attributes['lines'] ?? null;
        if (!\is_array($lines) || !array_is_list($lines) || !$lines) {
            throw JsonApiException::invalid('/data/attributes/lines', 'The lines must be a non-empty list.');
        }

        $quantities = [];
        foreach ($lines as $index => $line) {
            $pointer = "/data/attributes/lines/$index";
            if (!\is_array($line) || array_is_list($line)) {
                throw JsonApiException::invalid($pointer, 'Each line must be an object.');
            }

            $bookId = $line['bookId'] ?? null;
            if (!\is_int($bookId) || $bookId < 1) {
                throw JsonApiException::invalid("$pointer/bookId", 'The bookId must be a book id.');
            }

            $quantity = $line['quantity'] ?? null;
            if (!\is_int($quantity) || $quantity < 1 || $quantity > self::MAX_INT) {
                throw JsonApiException::invalid("$pointer/quantity", 'The quantity must be an integer of at least 1.');
            }

            $quantities[$bookId] ??= ['quantity' => 0, 'pointer' => "$pointer/bookId"];
            $quantities[$bookId]['quantity'] += $quantity;
        }

        return $quantities;
    }

    /**
     * @return 'accepted'|'declined'
     */
    private function charge(int $customerId, int $total): string
    {
        try {
            return $this->payment->charge($customerId, $total);
        } catch (PaymentFailed) {
            throw JsonApiException::create(502, 'Bad Gateway', 'The payment service failed.');
        }
    }
}
