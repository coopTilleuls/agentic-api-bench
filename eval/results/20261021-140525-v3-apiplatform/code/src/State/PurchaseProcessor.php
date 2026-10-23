<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Purchase;
use App\EventListener\CustomerIdListener;
use App\Exception\ApiException;
use App\Http\JsonApiBody;
use App\Service\BookshopRepository;

/**
 * @implements ProcessorInterface<Purchase, Purchase>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        $request = $context['request'];
        $customerId = $request->attributes->getInt(CustomerIdListener::ATTRIBUTE);
        $resource = JsonApiBody::resource($request, 'purchases');

        $lines = $this->lines($resource['attributes'] ?? null);
        $purchaseId = $this->repository->placePurchase($customerId, $lines);

        return PurchaseProvider::purchase($this->repository->findPurchase($customerId, $purchaseId));
    }

    /**
     * @return list<array{bookId: int, quantity: int}>
     */
    private function lines(mixed $attributes): array
    {
        if (!\is_array($attributes)) {
            throw ApiException::invalidBody('/data/attributes', 'The attributes must be an object with a "lines" list.');
        }

        $lines = $attributes['lines'] ?? null;
        if (!\is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw ApiException::invalidBody('/data/attributes/lines', 'At least one line is required.');
        }

        $violations = [];
        $valid = [];
        foreach ($lines as $index => $line) {
            $pointer = sprintf('/data/attributes/lines/%d', $index);
            if (!\is_array($line) || array_is_list($line)) {
                $violations[] = ['pointer' => $pointer, 'detail' => 'Each line must be an object.'];
                continue;
            }
            $lineValid = true;
            if (!\is_int($line['bookId'] ?? null) || $line['bookId'] < 1) {
                $violations[] = ['pointer' => $pointer . '/bookId', 'detail' => 'bookId must be a book id.'];
                $lineValid = false;
            }
            if (!\is_int($line['quantity'] ?? null) || $line['quantity'] < 1) {
                $violations[] = ['pointer' => $pointer . '/quantity', 'detail' => 'quantity must be an integer of at least 1.'];
                $lineValid = false;
            }
            if ($lineValid) {
                $valid[] = ['bookId' => $line['bookId'], 'quantity' => $line['quantity']];
            }
        }

        if ([] !== $violations) {
            throw ApiException::validation($violations);
        }

        return $valid;
    }
}
