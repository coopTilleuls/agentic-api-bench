<?php

namespace App\EventListener;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Api\ApiProblem;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

/**
 * Renders every error of the API as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 256)]
final class JsonApiErrorListener
{
    private const API_PATH = '#^/(books|authors|purchases|reports)(/|$)#';

    public function __invoke(ExceptionEvent $event): void
    {
        if (!preg_match(self::API_PATH, $event->getRequest()->getPathInfo())) {
            return;
        }

        $throwable = $event->getThrowable();
        if ($throwable instanceof ApiProblem) {
            $status = $throwable->getStatusCode();
            $errors = $throwable->getErrors();
        } elseif ($throwable instanceof ValidationException) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            $errors = [];
            foreach ($throwable->getConstraintViolationList() as $violation) {
                $errors[] = [
                    'detail' => (string) $violation->getMessage(),
                    'source' => ['pointer' => $this->pointer((string) $violation->getPropertyPath())],
                ];
            }
        } elseif ($throwable instanceof NotEncodableValueException) {
            $status = Response::HTTP_BAD_REQUEST;
            $errors = [['detail' => 'The request body is not valid JSON.']];
        } elseif ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $errors = [['detail' => $throwable->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error')]];
        } else {
            error_log((string) $throwable);
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $errors = [['detail' => 'An unexpected error occurred.']];
        }

        $title = Response::$statusTexts[$status] ?? 'Error';
        $document = array_map(
            static fn (array $error) => ['status' => (string) $status, 'title' => $title] + $error,
            $errors,
        );

        $event->setResponse(new JsonResponse(['errors' => $document], $status, ['Content-Type' => 'application/vnd.api+json']));
        $event->stopPropagation();
    }

    private function pointer(string $propertyPath): string
    {
        if ('' === $propertyPath) {
            return '/data';
        }

        return '/data/attributes/'.str_replace(['[', ']', '.'], ['/', '', '/'], $propertyPath);
    }
}
