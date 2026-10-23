<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\AuthorCollectionAction;
use App\Controller\AuthorItemAction;

#[ApiResource(
    shortName: 'Author',
    operations: [
        new GetCollection(uriTemplate: '/authors', controller: AuthorCollectionAction::class),
        new Get(uriTemplate: '/authors/{id}', controller: AuthorItemAction::class),
    ],
)]
final class Author
{
    public ?string $id = null;
    public ?string $name = null;
    public int $publishedBooks = 0;
}
