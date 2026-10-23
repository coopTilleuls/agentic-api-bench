<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Controller\BookShowController;
use App\Controller\BookUpdateController;
use App\Controller\BooksListController;

#[ApiResource(
    shortName: 'Book',
    operations: [
        new GetCollection(uriTemplate: '/books', controller: BooksListController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
        new Get(uriTemplate: '/books/{id}', controller: BookShowController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
        new Patch(uriTemplate: '/books/{id}', controller: BookUpdateController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
    ],
)]
final class Book
{
}
