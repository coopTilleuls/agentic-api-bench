<?php

namespace App\JsonApi;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders the errors of the API as JSON:API error documents.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 128)]
final class ErrorListener
{
    private const API_PATH = '#^/(books|authors|purchases|reports|openapi\.json)(/|$)#';

    public function __invoke(ExceptionEvent $event): void
    {
        if (!preg_match(self::API_PATH, $event->getRequest()->getPathInfo())) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($throwable instanceof JsonApiException) {
            $status = $throwable->status;
            $errors = $throwable->errors;
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $title = Response::$statusTexts[$status] ?? 'Error';
            $detail = $throwable->getMessage() && 404 !== $status ? $throwable->getMessage() : $title;
            $errors = [JsonApiException::error($status, $title, $detail)];
        } else {
            $status = 500;
            $errors = [JsonApiException::error(500, 'Internal Server Error', 'An unexpected error occurred.')];
        }

        $event->setResponse(new JsonResponse(['errors' => $errors], $status, ['Content-Type' => 'application/vnd.api+json']));
        $event->stopPropagation();
    }
}
