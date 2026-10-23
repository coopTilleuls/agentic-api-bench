<?php

namespace App\EventListener;

use App\Http\ApiException;
use App\JsonApi\JsonApi;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every error as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
final class JsonApiErrorListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof ApiException) {
            $status = $exception->status;
            $errors = $exception->errors;
            $headers = $exception->headers;
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $headers = $exception->getHeaders();
            $errors = [[
                'status' => (string) $status,
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'detail' => 404 === $status ? 'The requested resource does not exist.' : $exception->getMessage(),
            ]];
        } else {
            $this->logger->error('Unhandled error', ['exception' => $exception]);
            $status = 500;
            $headers = [];
            $errors = [[
                'status' => '500',
                'title' => Response::$statusTexts[500],
                'detail' => 'An unexpected error occurred.',
            ]];
        }

        $event->setResponse(JsonApi::respond(['errors' => $errors], $status, $headers));
    }
}
