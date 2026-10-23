<?php

namespace App\Http;

use App\ApiResource\Purchase;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer from the Customer-Id header before any purchase operation runs.
 */
final class CustomerIdentificationListener implements EventSubscriberInterface
{
    public const ATTRIBUTE = '_customer_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Runs after the router (32) has set the resource class, before anything else.
        return [KernelEvents::REQUEST => ['onRequest', 31]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (Purchase::class !== $request->attributes->get('_api_resource_class')) {
            return;
        }

        $customerId = QueryParser::identifier($request->headers->get('Customer-Id'));
        if (null === $customerId || !$this->customerExists($customerId)) {
            throw ApiException::single(401, 'A valid Customer-Id header is required.', ['parameter' => 'Customer-Id']);
        }

        $request->attributes->set(self::ATTRIBUTE, $customerId);
    }

    private function customerExists(int $customerId): bool
    {
        return (bool) $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM customer WHERE id = ?)', [$customerId]);
    }
}
