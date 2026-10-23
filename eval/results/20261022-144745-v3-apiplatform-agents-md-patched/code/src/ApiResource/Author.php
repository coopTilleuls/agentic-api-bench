<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\Api\AuthorItemController;
use App\Controller\Api\AuthorListController;

#[ApiResource(
    shortName: 'Author',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(uriTemplate: '/authors', controller: AuthorListController::class, output: false),
        new Get(uriTemplate: '/authors/{id}', controller: AuthorItemController::class, output: false),
    ],
)]
final class Author
{
}
