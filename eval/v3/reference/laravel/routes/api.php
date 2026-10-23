<?php

use App\Http\Controllers\BookController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\OpenApiController;
use Illuminate\Support\Facades\Route;
use LaravelJsonApi\Laravel\Http\Controllers\JsonApiController;
use LaravelJsonApi\Laravel\Facades\JsonApiRoute;
use LaravelJsonApi\Laravel\Routing\ResourceRegistrar;

JsonApiRoute::server('v1')->prefix('')->resources(function (ResourceRegistrar $server) {
    $server->resource('authors', JsonApiController::class)->only('index', 'show')->relationships(
        fn ($relationships) => $relationships->hasMany('books')->readOnly()
    );
    $server->resource('books', BookController::class)->only('index', 'show', 'update')->relationships(
        fn ($relationships) => $relationships->hasOne('author')->readOnly()
    );
    $server->resource('purchases', PurchaseController::class)->only('index', 'show', 'store')
        ;
});

Route::get('reports/sales', ReportController::class);
Route::get('openapi.json', OpenApiController::class);
