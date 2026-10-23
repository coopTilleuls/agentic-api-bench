<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Controller\BookList;
use App\Controller\BookShow;
use App\Controller\BookUpdate;

#[ApiResource(
    shortName: 'Book',
    operations: [
        new GetCollection(uriTemplate: '/books', controller: BookList::class),
        new Get(uriTemplate: '/books/{id}', controller: BookShow::class),
        new Patch(uriTemplate: '/books/{id}', controller: BookUpdate::class),
    ],
)]
final class Book
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
