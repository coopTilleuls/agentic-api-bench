<?php

namespace App\EventListener;

use App\Http\JsonApiBody;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * /openapi.json serves the OpenAPI 3.0 document, as every response of the API but the CSV report.
 */
final class OpenApiVersionListener
{
    private const PATH = '/openapi.json';

    #[AsEventListener(event: KernelEvents::REQUEST)]
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (self::PATH !== $request->getPathInfo() || $request->query->has('spec_version')) {
            return;
        }

        // API Platform defaults to OpenAPI 3.2 otherwise.
        $request->query->set('spec_version', '3.0.0');
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (self::PATH !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $event->getResponse()->headers->set('Content-Type', JsonApiBody::MEDIA_TYPE);
    }
}
