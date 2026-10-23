<?php

namespace App\EventListener;

use App\Http\JsonApiException;
use ApiPlatform\Metadata\Exception\HttpExceptionInterface as ApiPlatformHttpExceptionInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;

/**
 * Renders every error as a JSON:API error document, before API Platform's own error handling.
 */
final class JsonApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 256]];
    }

    public function onException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $error = $this->toJsonApiError($exception);

        $event->setResponse(new JsonResponse(
            ['errors' => $error->getErrors()],
            $error->getHttpStatus(),
            ['Content-Type' => 'application/vnd.api+json'],
        ));
        $event->stopPropagation();
    }

    private function toJsonApiError(\Throwable $exception): JsonApiException
    {
        if ($exception instanceof JsonApiException) {
            return $exception;
        }
        if ($exception instanceof HttpExceptionInterface || $exception instanceof ApiPlatformHttpExceptionInterface) {
            $detail = $exception->getMessage();

            return JsonApiException::create($exception->getStatusCode(), '' !== $detail ? $detail : 'The request could not be processed.');
        }
        if ($exception instanceof SerializerException || $exception instanceof \JsonException || $exception instanceof \UnexpectedValueException) {
            return JsonApiException::create(400, 'The request body is malformed.');
        }

        error_log((string) $exception);

        return JsonApiException::create(500, 'An unexpected error occurred.');
    }
}
