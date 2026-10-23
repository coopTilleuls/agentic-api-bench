<?php

namespace App\OpenApi;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves the OpenAPI document as OpenAPI 3.0, the version the API is documented in.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class OpenApiVersionListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ('/openapi.json' !== $request->getPathInfo()) {
            return;
        }

        // The document is served whatever the Accept header of the client, which is often the JSON:API one.
        $request->headers->set('Accept', 'application/vnd.openapi+json');
        if (!$request->query->has('spec_version')) {
            $request->query->set('spec_version', '3.0.0');
        }
    }
}
