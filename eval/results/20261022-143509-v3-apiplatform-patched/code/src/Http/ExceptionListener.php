<?php

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every exception as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
final class ExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof ApiException) {
            $event->setResponse(JsonApi::error($throwable));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $detail = $throwable->getMessage() ?: (JsonApi::errorObject(new ApiException($status, ''))['title']);
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $detail = 'An unexpected error occurred.';
        }

        $event->setResponse(JsonApi::error(new ApiException($status, $detail)));
    }
}
