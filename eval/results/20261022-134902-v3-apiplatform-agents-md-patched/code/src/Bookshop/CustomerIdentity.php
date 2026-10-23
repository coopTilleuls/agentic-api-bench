<?php

namespace App\Bookshop;

use App\JsonApi\JsonApiException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies the customer from the Customer-Id header.
 */
final class CustomerIdentity
{
    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @throws JsonApiException 401 when the header is missing, malformed or names an unknown customer
     */
    public function require(Request $request): int
    {
        $raw = $request->headers->get('Customer-Id');
        if (!is_string($raw) || 1 !== preg_match('/^[1-9]\d{0,9}$/', $raw) || !$this->store->customerExists((int) $raw)) {
            throw JsonApiException::create(401, 'Unauthorized', 'A valid Customer-Id header is required.');
        }

        return (int) $raw;
    }
}
