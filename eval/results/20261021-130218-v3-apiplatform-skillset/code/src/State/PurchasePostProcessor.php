<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Purchase;
use App\Http\ApiException;
use App\Http\CustomerIdentificationListener;
use App\Http\JsonApiBody;
use App\Payment\PaymentGateway;
use App\Payment\PaymentUnavailableException;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Buys books: validates the lines, reserves the stock, takes the payment and stores the purchase.
 *
 * @implements ProcessorInterface<Purchase, Purchase>
 */
final class PurchasePostProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly PaymentGateway $payments,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Purchase
    {
        $request = $this->requestStack->getCurrentRequest();
        $customerId = (int) $request->attributes->get(CustomerIdentificationListener::ATTRIBUTE);

        $attributes = JsonApiBody::data($request, 'purchases')['attributes'] ?? [];
        $lines = $this->validateLines(is_array($attributes) ? ($attributes['lines'] ?? null) : null);

        $purchaseId = $this->repository->placePurchase($customerId, $lines, function (int $amountCents) use ($customerId): void {
            try {
                $accepted = $this->payments->charge($customerId, $amountCents);
            } catch (PaymentUnavailableException) {
                throw ApiException::single(502, 'The payment service is unavailable.');
            }

            if (!$accepted) {
                throw ApiException::single(402, 'The payment was declined.');
            }
        });

        return Purchase::fromRow($this->repository->findPurchase($customerId, $purchaseId));
    }

    /**
     * @return list<array{bookId: int, quantity: int}>
     */
    private function validateLines(mixed $lines): array
    {
        if (!is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw ApiException::single(422, 'The lines must be a non-empty list.', ['pointer' => '/data/attributes/lines']);
        }

        $errors = [];
        $valid = [];
        foreach ($lines as $index => $line) {
            $pointer = sprintf('/data/attributes/lines/%d', $index);
            if (!is_array($line)) {
                $errors[] = ApiException::error(422, 'Each line must be an object.', ['pointer' => $pointer]);
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!is_int($bookId) || $bookId < 1) {
                $errors[] = ApiException::error(422, 'The bookId must be an integer.', ['pointer' => $pointer.'/bookId']);
            }
            if (!is_int($quantity) || $quantity < 1) {
                $errors[] = ApiException::error(422, 'The quantity must be an integer of at least 1.', ['pointer' => $pointer.'/quantity']);
            }
            if (is_int($bookId) && is_int($quantity) && $bookId >= 1 && $quantity >= 1) {
                $valid[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }

        if ([] !== $errors) {
            throw new ApiException(422, $errors);
        }

        return $valid;
    }
}
