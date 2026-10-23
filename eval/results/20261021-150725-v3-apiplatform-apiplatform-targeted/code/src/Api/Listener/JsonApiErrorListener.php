<?php

namespace App\Api\Listener;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\Error\JsonApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Renders the errors of the API as JSON:API error documents: our own errors, the HTTP errors (unknown paths, methods,
 * content types included, with their headers), and the validation errors of API Platform with JSON Pointers
 * ("/data/attributes/x").
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 100)]
final class JsonApiErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        $headers = [];

        if ($throwable instanceof JsonApiException) {
            $status = $throwable->status;
            $errors = $throwable->errors;
        } elseif ($throwable instanceof ValidationException) {
            $status = 422;
            $errors = [];
            foreach ($throwable->getConstraintViolationList() as $violation) {
                $errors[] = JsonApiException::error($status, (string) $violation->getMessage(), $this->pointer($violation));
            }
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $errors = [JsonApiException::error($status, $throwable->getMessage() ?: Response::$statusTexts[$status] ?? 'Error')];
            $headers = $throwable->getHeaders();
        } else {
            return;
        }

        $event->setResponse(new JsonResponse(['errors' => $errors], $status, ['Content-Type' => 'application/vnd.api+json'] + $headers));
        $event->stopPropagation();
    }

    private function pointer(ConstraintViolationInterface $violation): string
    {
        $path = (string) $violation->getPropertyPath();

        if ('' === $path) {
            return '/data';
        }

        return '/data/attributes/'.str_replace(['[', ']', '.'], ['/', '', '/'], $path);
    }
}
