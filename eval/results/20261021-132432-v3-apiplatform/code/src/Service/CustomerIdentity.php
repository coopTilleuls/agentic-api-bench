<?php

namespace App\Service;

use App\Http\ApiException;
use App\JsonApi\Query;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * The customer making the request, from the Customer-Id header.
 */
final class CustomerIdentity
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @throws ApiException 401 when the header is missing, malformed or names no customer */
    public function require(Request $request): int
    {
        $raw = trim((string) $request->headers->get('Customer-Id'));
        $id = Query::id($raw);
        if (null === $id || false === $this->db->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$id])) {
            throw ApiException::error(401, 'A valid Customer-Id header is required.');
        }

        return $id;
    }
}
