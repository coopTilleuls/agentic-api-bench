<?php

namespace App\EventListener;

use App\JsonApi\JsonApiException;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer of every purchase request from the Customer-Id header, before routing and any other check.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class CustomerIdentificationListener
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        if ('/purchases' !== $path && !str_starts_with($path, '/purchases/')) {
            return;
        }

        $header = $request->headers->get('Customer-Id');
        $customerId = is_string($header) && preg_match('/^[0-9]+$/', $header)
            ? filter_var($header, FILTER_VALIDATE_INT)
            : false;

        if (false === $customerId || false === $this->db->fetchOne('SELECT id FROM customer WHERE id = ?', [$customerId])) {
            throw JsonApiException::unauthorized('A valid Customer-Id header is required.');
        }

        $request->attributes->set('customerId', $customerId);
    }
}
