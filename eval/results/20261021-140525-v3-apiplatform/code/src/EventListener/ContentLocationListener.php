<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API Platform adds a Content-Location pointing at the resource with its format suffix
 * (/purchases/9.jsonapi), which is not a URL of the API: drop it, Location is kept.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class ContentLocationListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        if (str_ends_with((string) $headers->get('Content-Location'), '.jsonapi')) {
            $headers->remove('Content-Location');
        }
    }
}
