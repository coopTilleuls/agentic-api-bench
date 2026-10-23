<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\AuthorProvider;

#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(uriTemplate: '/authors', provider: AuthorProvider::class),
        new Get(uriTemplate: '/authors/{id}', requirements: ['id' => '\d+'], provider: AuthorProvider::class),
    ],
    formats: ['jsonapi' => ['application/vnd.api+json']],
)]
final class Author
{
    public int $id;

    public ?string $name = null;

    public int $publishedBooks = 0;

    /** @var list<Book>|null */
    public ?array $books = null;
}
