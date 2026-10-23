<?php

use App\JsonApi\DescribeHttpException;
use App\Http\Middleware\AuthenticateCustomer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use LaravelJsonApi\Core\Exceptions\JsonApiException;
use LaravelJsonApi\Exceptions\ExceptionParser;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AuthenticateCustomer::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport(JsonApiException::class);
        $exceptions->render(ExceptionParser::make()->prepend([DescribeHttpException::class])->acceptsAll()->renderable());
    })->create();
