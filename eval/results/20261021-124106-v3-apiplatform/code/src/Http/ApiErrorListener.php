<?php

namespace App\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Answers every failure with a JSON:API error document.
 */
final class ApiErrorListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 0]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $headers = [];

        if ($throwable instanceof ApiException) {
            $status = $throwable->status;
            $errors = $throwable->errors;
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $errors = [JsonApi::error($status, $throwable->getMessage() ?: 'The request failed.')];
            $headers = $throwable->getHeaders();
        } else {
            $status = 500;
            $errors = [JsonApi::error(500, 'An unexpected error occurred.')];
        }

        $event->setResponse(JsonApi::respond(['errors' => $errors], $status, $headers));
    }
}
