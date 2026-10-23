<?php

namespace App\EventListener;

use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every failure with a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class JsonApiErrorListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof JsonApiException) {
            $event->setResponse(JsonApi::document(['errors' => $throwable->errors], $throwable->status));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $throwable->getMessage() ?: $title;

            $event->setResponse(JsonApi::document(
                ['errors' => [JsonApi::error($status, $title, $detail)]],
                $status,
                $throwable->getHeaders(),
            ));

            return;
        }

        $this->logger->error('Unhandled exception: {message}', ['message' => $throwable->getMessage(), 'exception' => $throwable]);
        $event->setResponse(JsonApi::document(
            ['errors' => [JsonApi::error(500, 'Internal Server Error', 'An unexpected error occurred.')]],
            500,
        ));
    }
}
