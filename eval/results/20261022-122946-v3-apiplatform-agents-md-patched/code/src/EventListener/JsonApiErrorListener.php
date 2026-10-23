<?php

namespace App\EventListener;

use App\Http\JsonApiException;
use App\Http\JsonApiBody;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Psr\Log\LoggerInterface;

/**
 * Answers every failure as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 1000)]
final class JsonApiErrorListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof JsonApiException) {
            $status = $throwable->getStatus();
            $errors = $throwable->getErrors();
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $errors = [[
                'status' => (string) $status,
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'detail' => $throwable->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'),
            ]];
        } else {
            $this->logger->error('Unexpected error while answering a request: '.$throwable);
            $status = 500;
            $errors = [[
                'status' => '500',
                'title' => 'Internal Server Error',
                'detail' => 'An unexpected error occurred.',
            ]];
        }

        $event->setResponse(new JsonResponse(['errors' => $errors], $status, [
            'Content-Type' => JsonApiBody::MEDIA_TYPE,
        ]));
        $event->stopPropagation();
    }
}
