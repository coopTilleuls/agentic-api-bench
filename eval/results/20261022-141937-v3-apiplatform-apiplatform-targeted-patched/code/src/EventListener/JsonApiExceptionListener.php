<?php

namespace App\EventListener;

use App\Exception\JsonApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders {@see JsonApiException} as a JSON:API error document, ahead of API Platform's own error handling.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 512)]
final class JsonApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof JsonApiException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['errors' => $exception->getErrors()],
            $exception->getStatusCode(),
            ['Content-Type' => 'application/vnd.api+json'],
        ));
        $event->stopPropagation();
    }
}
