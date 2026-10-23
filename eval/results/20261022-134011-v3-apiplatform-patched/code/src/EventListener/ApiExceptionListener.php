<?php

namespace App\EventListener;

use App\JsonApi\ApiException;
use App\JsonApi\Document;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every failure as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
final class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof ApiException) {
            $response = Document::errors($throwable->errors(), $throwable->statusCode(), $throwable->headers());
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $response = Document::errors(
                [ApiException::error($status, $throwable->getMessage() ?: Response::$statusTexts[$status] ?? 'Error')],
                $status,
                $throwable->getHeaders(),
            );
        } else {
            $response = Document::errors(
                [ApiException::error(500, 'An unexpected error occurred.')],
                500,
            );
        }

        $event->setResponse($response);
        $event->stopPropagation();
    }
}
