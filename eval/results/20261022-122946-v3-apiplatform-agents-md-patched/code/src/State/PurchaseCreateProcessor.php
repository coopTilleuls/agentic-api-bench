<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Purchase;
use App\Http\JsonApiBody;
use App\Http\JsonApiException;
use App\Service\Catalog;
use App\Service\Checkout;
use Symfony\Component\HttpFoundation\Request;

/**
 * Buys the books of the request for the customer identified by the Customer-Id header.
 *
 * @implements ProcessorInterface<Purchase, Purchase|null>
 */
final class PurchaseCreateProcessor implements ProcessorInterface
{
    public function __construct(private readonly Checkout $checkout, private readonly Catalog $catalog)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?Purchase
    {
        /** @var Request $request */
        $request = $context['request'];
        $document = JsonApiBody::read($request);

        $resource = $document['data'] ?? null;
        if (!\is_array($resource) || array_is_list($resource)) {
            throw JsonApiException::validation([['pointer' => '/data', 'detail' => 'The document must contain a data object.']]);
        }
        if (\array_key_exists('type', $resource) && 'purchases' !== $resource['type']) {
            throw JsonApiException::single(409, 'Conflict', 'The data type must be purchases.');
        }

        $attributes = $resource['attributes'] ?? null;
        if (!\is_array($attributes) || array_is_list($attributes)) {
            throw JsonApiException::validation([['pointer' => '/data/attributes', 'detail' => 'The attributes must be an object.']]);
        }

        $lines = $attributes['lines'] ?? null;
        if (!\is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw JsonApiException::validation([['pointer' => '/data/attributes/lines', 'detail' => 'lines must be a non-empty list.']]);
        }

        $violations = [];
        $checked = [];
        foreach ($lines as $index => $line) {
            $pointer = '/data/attributes/lines/'.$index;
            if (!\is_array($line) || array_is_list($line)) {
                $violations[] = ['pointer' => $pointer, 'detail' => 'Each line must be an object.'];
                continue;
            }
            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!\is_int($bookId) || $bookId < 1) {
                $violations[] = ['pointer' => $pointer.'/bookId', 'detail' => 'bookId must be an integer.'];
            }
            if (!\is_int($quantity) || $quantity < 1) {
                $violations[] = ['pointer' => $pointer.'/quantity', 'detail' => 'quantity must be an integer of at least 1.'];
            }
            $checked[] = ['index' => $index, 'bookId' => $bookId, 'quantity' => $quantity];
        }
        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        $customerId = (int) $request->attributes->get('_customer_id');
        $purchaseId = $this->checkout->purchase($customerId, $checked);

        return $this->catalog->findPurchase($customerId, $purchaseId);
    }
}
