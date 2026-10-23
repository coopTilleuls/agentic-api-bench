<?php

declare(strict_types=1);

namespace App\Api;

use App\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer of every purchase request, before anything else runs.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final class CustomerIdListener
{
    public const ATTRIBUTE = '_customer_id';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !preg_match('#^/purchases(/|$)#', $request->getPathInfo())) {
            return;
        }

        $header = trim((string) $request->headers->get('Customer-Id', ''));
        $customerId = ctype_digit($header) && \strlen($header) <= 9 ? (int) $header : 0;

        if (0 === $customerId || null === $this->entityManager->find(Customer::class, $customerId)) {
            throw ApiException::status(401, 'The Customer-Id header is missing or does not identify a known customer.');
        }

        $request->attributes->set(self::ATTRIBUTE, $customerId);
    }
}
