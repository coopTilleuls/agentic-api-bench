<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API Platform sets the Location of a created resource as a path. Clients may follow it as it is, so make it a full URL.
 */
final class AbsoluteLocationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $location = $event->getResponse()->headers->get('Location');
        if (!$event->isMainRequest() || null === $location || !str_starts_with($location, '/')) {
            return;
        }

        $event->getResponse()->headers->set('Location', $event->getRequest()->getSchemeAndHttpHost().$location);
    }
}
