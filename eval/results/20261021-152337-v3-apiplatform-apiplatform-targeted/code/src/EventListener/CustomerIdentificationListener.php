<?php

namespace App\EventListener;

use App\Entity\Customer;
use App\Entity\Purchase;
use App\JsonApi\Query;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer of every purchase request from the Customer-Id header.
 * Runs before anything else on the request (content negotiation, body, validation)
 * so an unidentified request always answers 401.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 30)]
final class CustomerIdentificationListener
{
    public const ATTRIBUTE = '_customer_id';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || Purchase::class !== $request->attributes->get('_api_resource_class')) {
            return;
        }

        $customerId = $this->customerId($request);
        $customer = null === $customerId ? null : $this->entityManager->find(Customer::class, $customerId);
        if (null === $customer) {
            throw new HttpException(401, 'The Customer-Id header must hold the id of a known customer.');
        }

        $request->attributes->set(self::ATTRIBUTE, $customer->getId());
    }

    private function customerId(Request $request): ?int
    {
        $header = $request->headers->get('Customer-Id');

        return \is_string($header) ? Query::id($header) : null;
    }
}
