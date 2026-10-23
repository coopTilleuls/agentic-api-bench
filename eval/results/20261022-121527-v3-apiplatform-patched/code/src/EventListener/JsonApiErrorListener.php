<?php

namespace App\EventListener;

use App\JsonApi\ApiException;
use App\JsonApi\JsonApi;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every failure, routing errors included, as a JSON:API error document.
 */
final class JsonApiErrorListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', 255]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $headers = [];

        if ($throwable instanceof ApiException) {
            $status = $throwable->getStatus();
            $errors = $throwable->getErrors();
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $detail = $throwable->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error');
            $errors = [JsonApi::error($status, $detail)];
            $headers = $throwable->getHeaders();
        } else {
            error_log((string) $throwable);
            $status = 500;
            $errors = [JsonApi::error(500, 'An unexpected error occurred.')];
        }

        $event->setResponse(JsonApi::document(['errors' => $errors], $status, $headers));
        $event->stopPropagation();
    }
}
