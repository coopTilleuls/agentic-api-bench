<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\State\BookPatchProcessor;
use App\State\BookProvider;

#[ApiResource(
    shortName: 'books',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(uriTemplate: '/books', provider: BookProvider::class),
        new Get(uriTemplate: '/books/{id}', provider: BookProvider::class),
        new Patch(uriTemplate: '/books/{id}', provider: BookProvider::class, processor: BookPatchProcessor::class, input: false),
    ],
)]
final class Book
{
    public int $id;

    public string $title = '';

    public int $priceCents = 0;

    public ?string $publishedAt = null;

    public int $stock = 0;

    public ?Author $author = null;
}
