<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\AuthorProvider;

#[ApiResource(
    shortName: 'authors',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            queryParameterValidationEnabled: false,
            urlGenerationStrategy: UrlGeneratorInterface::ABS_URL,
            provider: AuthorProvider::class,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['books']], description: 'Related resources to include (books).'),
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, 1 to 50 (default 10).'),
            ],
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            queryParameterValidationEnabled: false,
            provider: AuthorProvider::class,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['books']], description: 'Related resources to include (books).'),
            ],
        ),
    ],
)]
final class Author
{
    public int $id = 0;
    public ?string $name = null;
    public int $publishedBooks = 0;

    /** @var list<Book> */
    public array $books = [];
}
