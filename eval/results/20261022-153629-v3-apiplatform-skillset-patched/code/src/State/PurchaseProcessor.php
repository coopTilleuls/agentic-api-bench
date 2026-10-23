<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Purchase;
use App\Http\JsonApiException;
use App\Payment\PaymentGateway;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * POST /purchases: validates the lines, checks the stock, takes the payment, then stores the purchase.
 * Nothing is stored and no stock changes unless every step succeeds.
 *
 * @implements ProcessorInterface<mixed, Purchase>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly BookshopMapper $mapper,
        private readonly PaymentGateway $payments,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        $request = $this->requestStack->getCurrentRequest() ?? throw new \LogicException('A request is required.');
        $customerId = $request->attributes->getInt('_customer_id');

        $document = JsonApiDocument::decode($request->getContent());
        $attributes = $document['data']['attributes'] ?? [];
        $lines = \is_array($attributes) ? ($attributes['lines'] ?? null) : null;
        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw JsonApiException::pointer(422, '/data/attributes/lines', 'The lines must be a non-empty list.');
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            $pointer = "/data/attributes/lines/$index";
            if (!\is_array($line) || array_is_list($line)) {
                $errors[] = [$pointer, 'Each line must be an object with bookId and quantity.'];
                continue;
            }
            if (!\is_int($line['bookId'] ?? null) || $line['bookId'] < 1) {
                $errors[] = ["$pointer/bookId", 'The bookId must be an integer.'];
            }
            if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                $errors[] = ["$pointer/quantity", 'The quantity must be an integer of at least 1.'];
            }
        }
        if ([] !== $errors) {
            throw JsonApiException::validation($errors);
        }

        return $this->repository->transactional(function () use ($lines, $customerId): Purchase {
            $bookIds = array_values(array_unique(array_column($lines, 'bookId')));
            $books = [];
            foreach ($this->repository->lockBooks($bookIds) as $row) {
                $books[(int) $row['id']] = $row;
            }

            $errors = [];
            foreach ($lines as $index => $line) {
                $book = $books[$line['bookId']] ?? null;
                if (null === $book || !BookshopRepository::isPublished($book['published'])) {
                    $errors[] = ["/data/attributes/lines/$index/bookId", \sprintf('The book %d is not available.', $line['bookId'])];
                }
            }
            if ([] !== $errors) {
                throw JsonApiException::validation($errors);
            }

            $requested = [];
            foreach ($lines as $line) {
                $requested[$line['bookId']] = ($requested[$line['bookId']] ?? 0) + $line['quantity'];
            }
            foreach ($requested as $bookId => $quantity) {
                if ((int) $books[$bookId]['stock'] < $quantity) {
                    throw JsonApiException::create(409, \sprintf('Not enough stock for the book %d: %d requested, %d available.', $bookId, $quantity, (int) $books[$bookId]['stock']));
                }
            }

            $total = 0;
            foreach ($lines as $line) {
                $total += $line['quantity'] * (int) $books[$line['bookId']]['price_cents'];
            }

            $this->payments->charge($customerId, $total);

            $purchaseId = $this->repository->insertPurchase($customerId, $total);
            foreach ($lines as $line) {
                $this->repository->insertPurchaseLine(
                    $purchaseId,
                    $line['bookId'],
                    $line['quantity'],
                    (int) $books[$line['bookId']]['price_cents'],
                );
            }
            foreach ($requested as $bookId => $quantity) {
                $this->repository->decreaseStock($bookId, $quantity);
            }

            $row = $this->repository->findPurchase($customerId, $purchaseId);

            return $this->mapper->purchases([$row])[0];
        });
    }
}
