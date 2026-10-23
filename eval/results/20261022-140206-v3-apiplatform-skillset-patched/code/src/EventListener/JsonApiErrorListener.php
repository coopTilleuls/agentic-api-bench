<?php

namespace App\EventListener;

use App\JsonApi\JsonApiDocument;
use App\JsonApi\JsonApiException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every error of the API as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 128)]
final class JsonApiErrorListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof JsonApiException) {
            $response = JsonApiDocument::errorResponse($exception);
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $response = JsonApiDocument::errorResponse(JsonApiException::create($status, $exception->getMessage() ?: Response::$statusTexts[$status] ?? 'Error'));
            $response->headers->add($exception->getHeaders());
        } else {
            $this->logger->error('Unexpected error', ['exception' => $exception]);
            $response = JsonApiDocument::errorResponse(JsonApiException::create(500, 'An unexpected error occurred.'));
        }

        $event->setResponse($response);
        $event->stopPropagation();
    }
}
