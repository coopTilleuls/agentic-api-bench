<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves the OpenAPI document as 3.0, the version the bookshop promises.
 * API Platform writes 3.2 unless asked for another spec_version.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
final class OpenApiVersionListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('openapi' === $request->attributes->get('_route') && !$request->query->has('spec_version')) {
            $request->query->set('spec_version', '3.0.0');
        }
    }
}
