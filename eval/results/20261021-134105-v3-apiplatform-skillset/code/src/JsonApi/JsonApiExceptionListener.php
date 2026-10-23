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
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 255)]
final class JsonApiExceptionListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $headers = ['Content-Type' => 'application/vnd.api+json'];

        if ($exception instanceof JsonApiException) {
            $status = $exception->getStatus();
            $errors = $exception->getErrors();
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $errors = [[
                'status' => (string) $status,
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'detail' => Response::$statusTexts[$status] ?? 'Error',
            ]];
            $headers += $exception->getHeaders();
        } else {
            $this->logger->error(sprintf('Unexpected error: %s: %s in %s:%d', $exception::class, $exception->getMessage(), $exception->getFile(), $exception->getLine()));
            $status = 500;
            $errors = [[
                'status' => '500',
                'title' => Response::$statusTexts[500],
                'detail' => 'An unexpected error occurred.',
            ]];
        }

        $event->setResponse(new JsonResponse(['errors' => $errors], $status, $headers));
        $event->stopPropagation();
    }
}
