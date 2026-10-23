<?php

namespace App\JsonApi;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Renders every error as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 0)]
final class ErrorListener
{
    public const CONTENT_TYPE = 'application/vnd.api+json';

    public function __construct(private readonly bool $debug = false)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $exception = $event->getThrowable();
        $headers = [];

        if ($exception instanceof ValidationException) {
            $status = Response::HTTP_UNPROCESSABLE_ENTITY;
            $errors = [];
            foreach ($exception->getConstraintViolationList() as $violation) {
                $errors[] = $this->violationError($violation, $status);
            }
        } else {
            $status = match (true) {
                $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
                $exception instanceof NotEncodableValueException => Response::HTTP_BAD_REQUEST,
                default => Response::HTTP_INTERNAL_SERVER_ERROR,
            };
            $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];
            $detail = match (true) {
                $exception instanceof NotEncodableValueException => 'The request body is not valid JSON.',
                $status >= 500 && !$this->debug => 'An internal error occurred.',
                default => $exception->getMessage(),
            };
            $error = [
                'status' => (string) $status,
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'detail' => '' !== $detail ? $detail : (Response::$statusTexts[$status] ?? 'Error'),
            ];
            if ($exception instanceof QueryParameterException) {
                $error['source'] = ['parameter' => $exception->getParameter()];
            }
            $errors = [$error];
        }

        $event->setResponse(new JsonResponse(
            ['errors' => $errors],
            $status,
            ['Content-Type' => self::CONTENT_TYPE] + $headers,
        ));
        $event->stopPropagation();
    }

    /**
     * @return array{status: string, title: string, detail: string, source: array{pointer: string}}
     */
    private function violationError(ConstraintViolationInterface $violation, int $status): array
    {
        return [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status],
            'detail' => (string) $violation->getMessage(),
            'source' => ['pointer' => self::pointer((string) $violation->getPropertyPath())],
        ];
    }

    /**
     * "lines[0].bookId" becomes "/data/attributes/lines/0/bookId".
     */
    public static function pointer(string $propertyPath): string
    {
        if ('' === $propertyPath) {
            return '/data';
        }

        return '/data/attributes/'.str_replace(['[', ']', '.'], ['/', '', '/'], $propertyPath);
    }
}
