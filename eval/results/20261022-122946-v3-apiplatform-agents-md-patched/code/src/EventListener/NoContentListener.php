<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns a response a processor marked as "no content" into an empty 204.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class NoContentListener
{
    public const ATTRIBUTE = '_bookshop_no_content';

    public function __invoke(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->getBoolean(self::ATTRIBUTE)) {
            return;
        }

        $response = $event->getResponse();
        $response->setStatusCode(204);
        $response->setContent('');
        $response->headers->remove('Content-Type');
    }
}
