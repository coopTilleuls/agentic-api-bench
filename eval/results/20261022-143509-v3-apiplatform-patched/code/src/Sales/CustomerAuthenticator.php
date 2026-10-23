<?php

namespace App\Sales;

use App\Http\ApiException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Customers identify themselves with the Customer-Id header.
 */
final class CustomerAuthenticator
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @throws ApiException 401 when the header is missing, malformed or names no customer
     */
    public function customerId(Request $request): int
    {
        $header = $request->headers->get('Customer-Id');
        $customerId = \is_string($header) && 1 === preg_match('/^\d{1,18}$/', $header) ? (int) $header : null;
        if (null === $customerId || !$this->db->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$customerId])) {
            throw new ApiException(401, 'A valid Customer-Id header is required.');
        }

        return $customerId;
    }
}
