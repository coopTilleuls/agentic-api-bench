<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Controller\Api\BookItemController;
use App\Controller\Api\BookListController;
use App\Controller\Api\BookUpdateController;

#[ApiResource(
    shortName: 'Book',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(uriTemplate: '/books', controller: BookListController::class, output: false),
        new Get(uriTemplate: '/books/{id}', controller: BookItemController::class, output: false),
        new Patch(uriTemplate: '/books/{id}', controller: BookUpdateController::class, output: false),
    ],
)]
final class Book
{
}
