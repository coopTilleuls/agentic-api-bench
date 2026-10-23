<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 128)]
final class ApiErrorListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!$exception instanceof ApiException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['errors' => $exception->getErrors()],
            $exception->getStatus(),
            ['Content-Type' => 'application/vnd.api+json'],
        ));
        $event->stopPropagation();
    }
}
