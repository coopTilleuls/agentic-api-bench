<?php

namespace App\EventListener;

use App\Http\JsonApiException;
use App\Repository\BookshopRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Purchases need a known customer in the Customer-Id header. This runs right after routing, before anything else
 * reads the request, so a request without a valid customer answers 401 whatever its body or other headers.
 */
final class CustomerIdentitySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 31]];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 1 !== preg_match('#^/purchases(/|$)#', $request->getPathInfo())) {
            return;
        }

        $header = $request->headers->get('Customer-Id');
        if (null === $header || 1 !== preg_match('/^[1-9]\d{0,17}$/', $header) || !$this->repository->customerExists((int) $header)) {
            throw JsonApiException::create(401, 'A valid Customer-Id header is required.');
        }

        $request->attributes->set('_customer_id', (int) $header);
    }
}
