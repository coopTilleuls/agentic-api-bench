<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves /openapi.json as OpenAPI 3.0: API Platform only downgrades the document when asked for it.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final class OpenApiVersionListener
{
    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if ($event->isMainRequest() && '/openapi.json' === $request->getPathInfo() && !$request->query->has('spec_version')) {
            $request->query->set('spec_version', '3.0.0');
        }
    }
}
