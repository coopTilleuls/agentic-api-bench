<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Customer\CustomerIdentifier;
use App\JsonApi\JsonApiDocument;
use App\JsonApi\JsonApiException;
use App\JsonApi\ResourceObjects;
use App\Payment\PaymentClient;
use App\Repository\BookRepository;
use App\Repository\PurchaseRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves POST /purchases: buys books for the customer making the request.
 *
 * @implements ProcessorInterface<mixed, Response>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly BookRepository $books,
        private readonly PurchaseRepository $purchases,
        private readonly CustomerIdentifier $customers,
        private readonly PaymentClient $payments,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        /** @var Request $request */
        $request = $context['request'];
        $customerId = $this->customers->identify($request);

        $lines = $this->parseLines($request);

        $purchaseId = $this->connection->transactional(fn (): int => $this->buy($customerId, $lines));

        $purchase = $this->purchases->findForCustomer($purchaseId, $customerId);
        $purchaseLines = $this->purchases->findLines([$purchaseId]);
        $location = "/purchases/$purchaseId";

        return JsonApiDocument::response(
            JsonApiDocument::single(ResourceObjects::purchase($purchase, $purchaseLines[$purchaseId] ?? [])),
            201,
            ['Location' => $location],
        );
    }

    /**
     * Stores the purchase and updates the stock. Runs in a transaction; any failure leaves everything unchanged.
     *
     * @param list<array{bookId: int, quantity: int, index: int}> $lines
     */
    private function buy(int $customerId, array $lines): int
    {
        $requested = [];
        foreach ($lines as $line) {
            $requested[$line['bookId']] = ($requested[$line['bookId']] ?? 0) + $line['quantity'];
        }

        $books = $this->books->lockByIds(array_keys($requested));

        $errors = [];
        foreach ($lines as $line) {
            $book = $books[$line['bookId']] ?? null;
            if (null === $book || !$this->isPublished($book)) {
                $errors[] = JsonApiException::errorObject(422, \sprintf('Book %d is not available.', $line['bookId']), "/data/attributes/lines/{$line['index']}/bookId");
            }
        }
        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        foreach ($requested as $bookId => $quantity) {
            if ((int) $books[$bookId]['stock'] < $quantity) {
                throw JsonApiException::create(409, \sprintf('Not enough copies of book %d in stock.', $bookId));
            }
        }

        $purchaseLines = [];
        $totalCents = 0;
        foreach ($lines as $line) {
            $unitPriceCents = (int) $books[$line['bookId']]['price_cents'];
            $purchaseLines[] = ['bookId' => $line['bookId'], 'quantity' => $line['quantity'], 'unitPriceCents' => $unitPriceCents];
            $totalCents += $unitPriceCents * $line['quantity'];
        }

        if (!$this->payments->charge($customerId, $totalCents)) {
            throw JsonApiException::create(402, 'The payment was declined.');
        }

        $purchaseId = $this->purchases->insert($customerId, $purchaseLines, $totalCents);
        foreach ($requested as $bookId => $quantity) {
            $this->books->decreaseStock($bookId, $quantity);
        }

        return $purchaseId;
    }

    /**
     * Checks the shape of every line and returns them with their position in the request.
     *
     * @return list<array{bookId: int, quantity: int, index: int}>
     */
    private function parseLines(Request $request): array
    {
        $document = json_decode($request->getContent(), true);
        if (!\is_array($document) || !\is_array($document['data'] ?? null)) {
            throw JsonApiException::create(400, 'The request body must be a JSON:API document with a "data" object.');
        }

        $resource = $document['data'];
        if (isset($resource['type']) && 'purchases' !== $resource['type']) {
            throw JsonApiException::create(409, 'The type of the resource does not match the endpoint.', '/data/type');
        }

        $attributes = $resource['attributes'] ?? null;
        if (!\is_array($attributes)) {
            throw JsonApiException::create(422, 'The "attributes" member is required.', '/data/attributes');
        }

        $lines = $attributes['lines'] ?? null;
        if (!\is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw JsonApiException::create(422, 'The purchase must have at least one line.', '/data/attributes/lines');
        }

        $errors = [];
        $parsed = [];
        foreach ($lines as $index => $line) {
            $pointer = "/data/attributes/lines/$index";
            if (!\is_array($line)) {
                $errors[] = JsonApiException::errorObject(422, 'A line must be an object.', $pointer);
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!\is_int($bookId) || $bookId < 1) {
                $errors[] = JsonApiException::errorObject(422, 'The bookId must be an integer.', "$pointer/bookId");
            }
            if (!\is_int($quantity) || $quantity < 1) {
                $errors[] = JsonApiException::errorObject(422, 'The quantity must be an integer of at least 1.', "$pointer/quantity");
            }
            if (\is_int($bookId) && $bookId >= 1 && \is_int($quantity) && $quantity >= 1) {
                $parsed[] = ['bookId' => $bookId, 'quantity' => $quantity, 'index' => $index];
            }
        }

        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $book
     */
    private function isPublished(array $book): bool
    {
        return \in_array($book['published'], [true, 't', '1', 1], true);
    }
}
