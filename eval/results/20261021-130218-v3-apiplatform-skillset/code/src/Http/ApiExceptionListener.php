<?php

namespace App\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every error as a JSON:API error document.
 *
 * Runs before the framework and API Platform error handling, which would otherwise pick their own format.
 */
final class ApiExceptionListener implements EventSubscriberInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 256]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $headers = [];

        if ($exception instanceof ApiException) {
            $status = $exception->status;
            $errors = $exception->errors;
        } else {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
            $detail = $status < 500 ? ($exception->getMessage() ?: Response::$statusTexts[$status] ?? 'Error') : 'An internal error occurred.';
            $errors = [ApiException::error($status, $detail)];
            if ($exception instanceof HttpExceptionInterface) {
                $headers = $exception->getHeaders();
            }
            if ($status >= 500) {
                $this->logger->error('Unhandled exception: {message}', ['message' => $exception->getMessage(), 'exception' => $exception]);
            }
        }

        $event->setResponse(new Response(
            json_encode(['errors' => $errors], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $status,
            $headers + ['Content-Type' => 'application/vnd.api+json'],
        ));
        $event->stopPropagation();
    }
}
