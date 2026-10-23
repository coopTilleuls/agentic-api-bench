<?php

namespace App\JsonApi;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer on the purchase routes, before anything else is checked.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class CustomerListener
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!preg_match('#^/purchases(/|$)#', $request->getPathInfo())) {
            return;
        }

        $header = $request->headers->get('Customer-Id');
        if (null === $header || !preg_match('/^[1-9][0-9]*$/', $header)
            || !$this->db->fetchOne('SELECT 1 FROM customer WHERE id = ?', [(int) $header])) {
            throw JsonApiException::create(401, 'Unauthorized', 'A valid Customer-Id header is required.');
        }

        $request->attributes->set('customer_id', (int) $header);
    }
}
