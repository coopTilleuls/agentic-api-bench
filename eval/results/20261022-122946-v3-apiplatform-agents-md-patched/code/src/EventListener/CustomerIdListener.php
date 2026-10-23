<?php

namespace App\EventListener;

use App\Http\JsonApiException;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every /purchases request must name an existing customer, checked before anything else.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class CustomerIdListener
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

        $header = (string) $request->headers->get('Customer-Id', '');
        $unauthorized = JsonApiException::single(401, 'Unauthorized', 'A Customer-Id header naming an existing customer is required.');
        if (1 !== preg_match('/^[1-9][0-9]{0,8}$/', $header)) {
            throw $unauthorized;
        }
        if (false === $this->db->fetchOne('SELECT 1 FROM customer WHERE id = :id', ['id' => (int) $header])) {
            throw $unauthorized;
        }

        $request->attributes->set('_customer_id', (int) $header);
    }
}
