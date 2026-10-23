<?php

namespace App\EventListener;

use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every failure as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
final class JsonApiErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof JsonApiException) {
            $response = JsonApiResponse::errors($throwable->errors, $throwable->status, $throwable->headers);
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = '' !== $throwable->getMessage() ? $throwable->getMessage() : $title;
            $response = JsonApiResponse::errors(
                [['status' => (string) $status, 'title' => $title, 'detail' => $detail]],
                $status,
                $throwable->getHeaders(),
            );
        } else {
            $response = JsonApiResponse::errors(
                [['status' => '500', 'title' => 'Internal Server Error', 'detail' => 'An unexpected error occurred.']],
                500,
            );
        }

        $event->setResponse($response);
        $event->stopPropagation();
    }
}
