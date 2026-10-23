<?php

namespace App\JsonApi;

use Closure;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DescribeHttpException
{
    public function handle(Throwable $exception, Closure $next)
    {
        if ($exception instanceof HttpExceptionInterface && '' === $exception->getMessage()) {
            $status = $exception->getStatusCode();
            $exception = new HttpException($status, Response::$statusTexts[$status] ?? 'Error', $exception, $exception->getHeaders());
        }

        return $next($exception);
    }
}
