<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\AuthorGetController;
use App\Controller\AuthorListController;

#[ApiResource(
    shortName: 'authors',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(uriTemplate: '/authors', controller: AuthorListController::class, input: false, output: false),
        new Get(uriTemplate: '/authors/{id}', controller: AuthorGetController::class, input: false, output: false),
    ],
)]
final class Author
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
