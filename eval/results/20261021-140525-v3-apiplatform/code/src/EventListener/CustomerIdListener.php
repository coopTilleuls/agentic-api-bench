<?php

namespace App\EventListener;

use App\Exception\ApiException;
use App\Service\BookshopRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Identifies the customer of the purchases endpoints from the Customer-Id header.
 * Runs before routing, so a missing or unknown customer answers 401 whatever else is wrong.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class CustomerIdListener
{
    public const ATTRIBUTE = '_customer_id';

    public function __construct(private readonly BookshopRepository $repository)
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
        $customerId = null !== $header && ctype_digit($header) && \strlen($header) <= 9 ? (int) $header : null;
        if (null === $customerId || !$this->repository->customerExists($customerId)) {
            throw ApiException::single(401, 'Unauthorized', 'A valid Customer-Id header is required.');
        }

        $request->attributes->set(self::ATTRIBUTE, $customerId);
    }
}
