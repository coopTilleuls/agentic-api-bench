<?php

namespace App\JsonApi;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every error as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 512)]
final class ErrorListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $headers = [];

        if ($e instanceof JsonApiException) {
            $status = $e->getStatus();
            $errors = $e->getErrors();
        } elseif ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $headers = $e->getHeaders();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $errors = [JsonApiException::error($status, $title, $e->getMessage() ?: $title)];
        } else {
            $status = 500;
            $this->logger->error('Unexpected error: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
            $errors = [JsonApiException::error(500, 'Internal Server Error', 'An unexpected error occurred.')];
        }

        $event->setResponse(new JsonResponse(['errors' => $errors], $status, $headers + ['Content-Type' => 'application/vnd.api+json']));
    }
}
