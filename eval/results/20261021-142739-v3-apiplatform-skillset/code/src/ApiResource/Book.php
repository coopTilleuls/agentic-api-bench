<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\State\BookProcessor;
use App\State\BookProvider;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ApiResource(
    shortName: 'books',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            provider: BookProvider::class,
            queryParameterValidationEnabled: false,
            parameters: [
                new QueryParameter(key: 'filter[title]', description: 'Title contains the text, case-insensitive.', schema: ['type' => 'string']),
                new QueryParameter(key: 'filter[author]', description: 'Author name, exact.', schema: ['type' => 'string']),
                new QueryParameter(key: 'filter[minPrice]', description: 'Minimum price in cents, inclusive.', schema: ['type' => 'integer']),
                new QueryParameter(key: 'filter[maxPrice]', description: 'Maximum price in cents, inclusive.', schema: ['type' => 'integer']),
                new QueryParameter(key: 'filter[publishedAfter]', description: 'Published on or after this date (YYYY-MM-DD).', schema: ['type' => 'string', 'format' => 'date']),
                new QueryParameter(key: 'filter[publishedBefore]', description: 'Published on or before this date (YYYY-MM-DD).', schema: ['type' => 'string', 'format' => 'date']),
                new QueryParameter(key: 'filter[inStock]', description: 'true: stock greater than 0; false: stock 0.', schema: ['type' => 'boolean']),
                new QueryParameter(key: 'sort', description: 'Comma separated fields among priceCents, title, publishedAt; prefix with - for descending. Ties broken by id.', schema: ['type' => 'string']),
                new QueryParameter(key: 'include', description: 'Related resources to include: author.', schema: ['type' => 'string']),
                new QueryParameter(key: 'page[number]', description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1]),
                new QueryParameter(key: 'page[size]', description: 'Items per page, from 1 to 50 (default 10).', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]),
            ],
        ),
        new Get(
            uriTemplate: '/books/{id}',
            requirements: ['id' => '\d+'],
            provider: BookProvider::class,
            queryParameterValidationEnabled: false,
            parameters: [
                new QueryParameter(key: 'include', description: 'Related resources to include: author.', schema: ['type' => 'string']),
            ],
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            requirements: ['id' => '\d+'],
            provider: BookProvider::class,
            processor: BookProcessor::class,
            input: false,
            queryParameterValidationEnabled: false,
        ),
    ],
)]
final class Book
{
    #[ApiProperty(identifier: true)]
    #[Ignore]
    public int $id = 0;

    public string $title = '';

    public int $priceCents = 0;

    public ?string $publishedAt = null;

    public int $stock = 0;

    public ?Author $author = null;
}
