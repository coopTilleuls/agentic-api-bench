<?php

namespace App\EventListener;

use App\Customer\CurrentCustomer;
use App\Entity\Customer;
use App\Exception\JsonApiException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every purchase request must carry a known Customer-Id header. Checked before routing, so before anything else.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class CustomerIdentificationListener
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !preg_match('#^/purchases(/|$)#', $request->getPathInfo())) {
            return;
        }

        $header = $request->headers->get('Customer-Id');
        $customerId = \is_string($header) && preg_match('/^[1-9][0-9]{0,8}$/', $header) ? (int) $header : null;
        if (null === $customerId || null === $this->entityManager->find(Customer::class, $customerId)) {
            throw JsonApiException::one(401, 'The Customer-Id header is missing or does not identify a customer.');
        }

        $request->attributes->set(CurrentCustomer::ATTRIBUTE, $customerId);
    }
}
