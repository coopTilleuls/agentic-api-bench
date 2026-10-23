<?php

namespace App\JsonApi;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every failure as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 64)]
final class ErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if ($throwable instanceof JsonApiException) {
            $event->setResponse(Document::response(['errors' => $throwable->getErrors()], $throwable->getStatus()));

            return;
        }

        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        $title = Response::$statusTexts[$status] ?? 'Error';
        $detail = $status < 500 && '' !== $throwable->getMessage() ? $throwable->getMessage() : $title;
        $headers = $throwable instanceof HttpExceptionInterface ? $throwable->getHeaders() : [];

        $event->setResponse(Document::response([
            'errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]],
        ], $status, $headers));
    }
}
