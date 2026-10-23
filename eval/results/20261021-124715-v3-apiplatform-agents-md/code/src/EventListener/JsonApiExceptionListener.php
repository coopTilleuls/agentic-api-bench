<?php

namespace App\EventListener;

use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every error of the API as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 512)]
final class JsonApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if ($exception instanceof JsonApiException) {
            $event->setResponse(JsonApi::errorResponse($exception->getStatus(), $exception->getErrors()));

            return;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;
        $title = Response::$statusTexts[$status] ?? 'Error';
        $detail = $status < 500 && '' !== $exception->getMessage()
            ? $exception->getMessage()
            : $title;

        $event->setResponse(JsonApi::errorResponse($status, [[
            'status' => (string) $status,
            'title' => $title,
            'detail' => $detail,
        ]]));
    }
}
