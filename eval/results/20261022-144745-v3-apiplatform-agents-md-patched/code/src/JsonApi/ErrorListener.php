<?php

namespace App\JsonApi;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Renders every error of the API as a JSON:API error document.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 1024)]
final class ErrorListener
{
    public function __construct(#[Autowire('%kernel.debug%')] private readonly bool $debug)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $headers = [];

        if ($exception instanceof JsonApiException) {
            $status = $exception->status;
            $errors = $exception->errors;
        } elseif ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $headers = $exception->getHeaders();
            $errors = [JsonApi::error(
                $status,
                Response::$statusTexts[$status] ?? 'Error',
                $exception->getMessage() ?: (Response::$statusTexts[$status] ?? 'Error'),
            )];
        } else {
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $detail = $this->debug ? $exception->getMessage() : 'An unexpected error occurred.';
            $errors = [JsonApi::error($status, Response::$statusTexts[$status], $detail)];
        }

        $event->setResponse(JsonApi::response(['errors' => $errors], $status, $headers));
    }
}
