<?php

namespace App\Customer;

use App\Entity\Customer;
use App\JsonApi\JsonApiException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The customer identified by the Customer-Id header, answering 401 when missing, malformed or unknown.
 */
final class CurrentCustomer
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function get(): Customer
    {
        $id = $this->requestStack->getCurrentRequest()?->headers->get('Customer-Id');
        if (null === $id || 1 !== preg_match('/^\d+$/', $id)) {
            throw JsonApiException::error(401, 'A valid Customer-Id header is required.', ['header' => 'Customer-Id']);
        }

        $customer = $this->em->find(Customer::class, (int) $id);
        if (null === $customer) {
            throw JsonApiException::error(401, 'Unknown customer.', ['header' => 'Customer-Id']);
        }

        return $customer;
    }
}
