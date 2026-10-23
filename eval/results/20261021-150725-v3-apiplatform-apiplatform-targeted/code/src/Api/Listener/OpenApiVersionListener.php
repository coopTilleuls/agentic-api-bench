<?php

namespace App\Api\Listener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The document served at /openapi.json is a single OpenAPI 3.0.0 document, whatever the client sends: API Platform
 * documents 3.2 unless asked for 3.0.0 with the spec_version query parameter, and negotiates the format on the Accept
 * header, which clients of this API set to application/vnd.api+json.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class OpenApiVersionListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || '/openapi.json' !== $request->getPathInfo()) {
            return;
        }

        if (!$request->query->has('spec_version')) {
            $request->query->set('spec_version', '3.0.0');
        }

        $request->headers->set('Accept', 'application/vnd.openapi+json');
    }
}
