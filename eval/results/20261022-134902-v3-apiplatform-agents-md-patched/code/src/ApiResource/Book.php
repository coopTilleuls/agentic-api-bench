<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Controller\BookCollectionAction;
use App\Controller\BookItemAction;
use App\Controller\BookUpdateAction;

#[ApiResource(
    shortName: 'Book',
    operations: [
        new GetCollection(uriTemplate: '/books', controller: BookCollectionAction::class),
        new Get(uriTemplate: '/books/{id}', controller: BookItemAction::class),
        new Patch(uriTemplate: '/books/{id}', controller: BookUpdateAction::class),
    ],
)]
final class Book
{
    public ?string $id = null;
    public string $title = '';
    public int $priceCents = 0;
    public ?string $publishedAt = null;
    public int $stock = 0;
}
