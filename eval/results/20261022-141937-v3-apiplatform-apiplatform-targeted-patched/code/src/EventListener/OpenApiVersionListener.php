<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The OpenAPI document is served as OpenAPI 3.0 unless another version is asked for.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
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
