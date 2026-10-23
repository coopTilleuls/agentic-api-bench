<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\State\AuthorProvider;

#[ApiResource(
    shortName: 'authors',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(uriTemplate: '/authors', provider: AuthorProvider::class),
        new Get(uriTemplate: '/authors/{id}', provider: AuthorProvider::class),
    ],
)]
final class Author
{
    public int $id;

    public ?string $name = null;

    public int $publishedBooks = 0;

    /** @var list<Book> the published books of the author, in id order */
    public array $books = [];
}
