<?php

namespace App\EventListener;

use App\Exception\ApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every error as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
final class ErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof ApiException) {
            $status = $exception->getStatus();
            $errors = $exception->getErrors();
        } else {
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            if ($status >= 500) {
                error_log((string) $exception);
            }
            $errors = [[
                'title' => $this->title($status),
                'detail' => $status >= 500 ? 'Internal server error' : $exception->getMessage(),
            ]];
        }

        $document = ['errors' => array_map(static fn (array $error): array => ['status' => (string) $status] + $error, $errors)];

        $event->setResponse(new JsonResponse($document, $status, [
            'Content-Type' => 'application/vnd.api+json',
        ]));
    }

    private function title(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            409 => 'Conflict',
            415 => 'Unsupported Media Type',
            422 => 'Unprocessable Entity',
            502 => 'Bad Gateway',
            default => 'Error',
        };
    }
}
