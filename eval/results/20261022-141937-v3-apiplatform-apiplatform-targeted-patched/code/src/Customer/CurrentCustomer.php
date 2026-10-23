<?php

namespace App\Customer;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The customer identified by the Customer-Id header, as checked by {@see \App\EventListener\CustomerIdentificationListener}.
 */
final class CurrentCustomer
{
    public const ATTRIBUTE = '_customer_id';

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function id(): ?int
    {
        return $this->requestStack->getCurrentRequest()?->attributes->get(self::ATTRIBUTE);
    }
}
