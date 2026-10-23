<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\State\BookProcessor;
use App\State\BookProvider;

#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(uriTemplate: '/books', provider: BookProvider::class),
        new Get(uriTemplate: '/books/{id}', requirements: ['id' => '\d+'], provider: BookProvider::class),
        new Patch(
            uriTemplate: '/books/{id}',
            requirements: ['id' => '\d+'],
            input: false,
            provider: BookProvider::class,
            processor: BookProcessor::class,
        ),
    ],
    formats: ['jsonapi' => ['application/vnd.api+json']],
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
