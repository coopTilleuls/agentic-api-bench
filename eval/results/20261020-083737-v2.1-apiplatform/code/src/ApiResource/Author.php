<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\AuthorShowController;
use App\Controller\AuthorsListController;

#[ApiResource(
    shortName: 'Author',
    operations: [
        new GetCollection(uriTemplate: '/authors', controller: AuthorsListController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
        new Get(uriTemplate: '/authors/{id}', controller: AuthorShowController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
    ],
)]
final class Author
{
}
