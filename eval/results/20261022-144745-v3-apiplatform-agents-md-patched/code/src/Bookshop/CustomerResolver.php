<?php

namespace App\Bookshop;

use App\JsonApi\JsonApiException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Identifies the customer of a request from its Customer-Id header.
 */
final class CustomerResolver
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function resolve(Request $request): int
    {
        $raw = $request->headers->get('Customer-Id');

        if (null === $raw || !preg_match('/^[1-9]\d{0,8}$/', $raw)) {
            throw JsonApiException::unauthorized('A Customer-Id header identifying an existing customer is required.');
        }

        $id = (int) $raw;
        if (!$this->db->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$id], [ParameterType::INTEGER])) {
            throw JsonApiException::unauthorized('A Customer-Id header identifying an existing customer is required.');
        }

        return $id;
    }
}
