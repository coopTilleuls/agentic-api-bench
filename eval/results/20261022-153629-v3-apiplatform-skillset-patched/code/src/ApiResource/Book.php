<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiResource\Input\BookUpdate;
use App\State\BookProcessor;
use App\State\BookProvider;

#[ApiResource(
    shortName: 'books',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            queryParameterValidationEnabled: false,
            urlGenerationStrategy: UrlGeneratorInterface::ABS_URL,
            provider: BookProvider::class,
            parameters: [
                new QueryParameter(key: 'filter[title]', schema: ['type' => 'string'], description: 'Title contains the text, case-insensitive.'),
                new QueryParameter(key: 'filter[author]', schema: ['type' => 'string'], description: 'Author name, exact.'),
                new QueryParameter(key: 'filter[minPrice]', schema: ['type' => 'integer'], description: 'Minimum price in cents, inclusive.'),
                new QueryParameter(key: 'filter[maxPrice]', schema: ['type' => 'integer'], description: 'Maximum price in cents, inclusive.'),
                new QueryParameter(key: 'filter[publishedAfter]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or after this date (YYYY-MM-DD).'),
                new QueryParameter(key: 'filter[publishedBefore]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or before this date (YYYY-MM-DD).'),
                new QueryParameter(key: 'filter[inStock]', schema: ['type' => 'boolean'], description: 'true: stock greater than 0; false: stock 0.'),
                new QueryParameter(key: 'sort', schema: ['type' => 'string'], description: 'Comma separated fields among priceCents, title, publishedAt; prefix with - for descending. Ties are broken by id ascending.'),
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['author']], description: 'Related resources to include (author).'),
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, 1 to 50 (default 10).'),
            ],
        ),
        new Get(
            uriTemplate: '/books/{id}',
            queryParameterValidationEnabled: false,
            provider: BookProvider::class,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['author']], description: 'Related resources to include (author).'),
            ],
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            queryParameterValidationEnabled: false,
            provider: BookProvider::class,
            processor: BookProcessor::class,
            input: BookUpdate::class,
        ),
    ],
)]
final class Book
{
    public int $id = 0;
    public string $title = '';
    public int $priceCents = 0;
    public ?string $publishedAt = null;
    public int $stock = 0;
    public ?Author $author = null;
}
