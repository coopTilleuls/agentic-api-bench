<?php

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every API error as {"error": "..."} instead of the API Platform problem format.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
final class JsonErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof ApiError) {
            $status = $throwable->status;
            $message = $throwable->getMessage();
            $headers = [];
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $message = 404 === $status ? 'Not found' : ($throwable->getMessage() ?: 'Error');
            $headers = $throwable->getHeaders();
        } else {
            return;
        }

        $event->setResponse(new JsonResponse(['error' => $message], $status, $headers));
        $event->stopPropagation();
    }
}
