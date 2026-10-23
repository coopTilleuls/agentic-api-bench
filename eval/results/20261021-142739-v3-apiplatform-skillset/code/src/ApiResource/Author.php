<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\AuthorProvider;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ApiResource(
    shortName: 'authors',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            provider: AuthorProvider::class,
            queryParameterValidationEnabled: false,
            parameters: [
                new QueryParameter(key: 'include', description: 'Related resources to include: books.', schema: ['type' => 'string']),
                new QueryParameter(key: 'page[number]', description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1]),
                new QueryParameter(key: 'page[size]', description: 'Items per page, from 1 to 50 (default 10).', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]),
            ],
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            requirements: ['id' => '\d+'],
            provider: AuthorProvider::class,
            queryParameterValidationEnabled: false,
            parameters: [
                new QueryParameter(key: 'include', description: 'Related resources to include: books.', schema: ['type' => 'string']),
            ],
        ),
    ],
)]
final class Author
{
    #[ApiProperty(identifier: true)]
    #[Ignore]
    public int $id = 0;

    public ?string $name = null;

    public int $publishedBooks = 0;

    /** @var list<Book> */
    public array $books = [];
}
