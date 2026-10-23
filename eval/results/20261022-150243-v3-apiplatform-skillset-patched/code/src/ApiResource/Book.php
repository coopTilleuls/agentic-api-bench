<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Controller\BookGetController;
use App\Controller\BookListController;
use App\Controller\BookPatchController;

#[ApiResource(
    shortName: 'books',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(uriTemplate: '/books', controller: BookListController::class, input: false, output: false),
        new Get(uriTemplate: '/books/{id}', controller: BookGetController::class, input: false, output: false),
        new Patch(uriTemplate: '/books/{id}', controller: BookPatchController::class, input: false, output: false),
    ],
)]
final class Book
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
