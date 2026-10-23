<?php

namespace App\JsonApi;

use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer of the purchase endpoints from the Customer-Id header,
 * before routing and before anything else.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class CustomerIdentificationListener
{
    public const ATTRIBUTE = '_customer_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!preg_match('#^/purchases(/|$)#', $request->getPathInfo())) {
            return;
        }

        $header = (string) $request->headers->get('Customer-Id', '');
        if (!preg_match('/^\d{1,18}$/', $header) || !$this->customerExists((int) $header)) {
            throw JsonApiException::single(401, 'Unauthorized', 'A valid Customer-Id header is required.');
        }

        $request->attributes->set(self::ATTRIBUTE, (int) $header);
    }

    private function customerExists(int $id): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$id]);
    }
}
