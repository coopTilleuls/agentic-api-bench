<?php

namespace App\Customer;

use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiQuery;
use App\Repository\PurchaseRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies the customer making a request from its Customer-Id header.
 */
final class CustomerIdentifier
{
    public const HEADER = 'Customer-Id';

    public function __construct(private readonly PurchaseRepository $purchases)
    {
    }

    /**
     * @throws JsonApiException 401 when the header is missing, malformed or names no customer
     */
    public function identify(Request $request): int
    {
        $customerId = JsonApiQuery::id((string) $request->headers->get(self::HEADER, ''));
        if (null === $customerId || !$this->purchases->customerExists($customerId)) {
            throw JsonApiException::create(401, 'A valid Customer-Id header is required.');
        }

        return $customerId;
    }
}
