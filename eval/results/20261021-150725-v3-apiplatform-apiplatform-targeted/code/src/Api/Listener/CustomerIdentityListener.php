<?php

namespace App\Api\Listener;

use App\Api\Error\JsonApiException;
use App\Entity\Customer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer of the purchase endpoints before anything else (routing, body, parameters).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class CustomerIdentityListener
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

        if (null === $header || !preg_match('/^[1-9][0-9]{0,8}$/', $header)
            || null === $this->entityManager->find(Customer::class, (int) $header)) {
            throw JsonApiException::single(401, 'A valid Customer-Id header is required.');
        }

        $request->attributes->set('customer_id', (int) $header);
    }
}
