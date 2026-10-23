<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * /openapi.json always answers the OpenAPI 3.0 document, whatever the Accept header of the client
 * (the API sends application/vnd.api+json, which the documentation action does not know).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class OpenApiAcceptListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('/openapi.json' !== $request->getPathInfo()) {
            return;
        }

        $request->headers->set('Accept', 'application/vnd.openapi+json');
        $request->query->set('spec_version', '3.0.0');
    }
}
