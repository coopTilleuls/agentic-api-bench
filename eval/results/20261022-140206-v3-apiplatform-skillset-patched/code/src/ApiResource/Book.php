<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\State\BookPatchProcessor;
use App\State\BookProvider;

/**
 * A published book, as served by /books. Unpublished books are only reachable through PATCH.
 */
#[ApiResource(
    shortName: 'Book',
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            queryParameterValidationEnabled: false,
            provider: BookProvider::class,
            parameters: [
                'filter[title]' => new QueryParameter(key: 'filter[title]', schema: ['type' => 'string'], description: 'Title contains the text, case-insensitive.'),
                'filter[author]' => new QueryParameter(key: 'filter[author]', schema: ['type' => 'string'], description: 'Author name, exact.'),
                'filter[minPrice]' => new QueryParameter(key: 'filter[minPrice]', schema: ['type' => 'integer'], description: 'Minimum price in cents, included.'),
                'filter[maxPrice]' => new QueryParameter(key: 'filter[maxPrice]', schema: ['type' => 'integer'], description: 'Maximum price in cents, included.'),
                'filter[publishedAfter]' => new QueryParameter(key: 'filter[publishedAfter]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or after this date.'),
                'filter[publishedBefore]' => new QueryParameter(key: 'filter[publishedBefore]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or before this date.'),
                'filter[inStock]' => new QueryParameter(key: 'filter[inStock]', schema: ['type' => 'boolean'], description: 'true: stock above 0; false: stock 0.'),
                'sort' => new QueryParameter(key: 'sort', schema: ['type' => 'string'], description: 'Comma-separated fields (priceCents, title, publishedAt), each prefixed with - for descending order.'),
                'include' => new QueryParameter(key: 'include', schema: ['type' => 'string'], description: 'Related resources to include: author.'),
                'page[number]' => new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1. Default 1.'),
                'page[size]' => new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, from 1 to 50. Default 10.'),
            ],
        ),
        new Get(
            uriTemplate: '/books/{id}',
            queryParameterValidationEnabled: false,
            provider: BookProvider::class,
            parameters: [
                'include' => new QueryParameter(key: 'include', schema: ['type' => 'string'], description: 'Related resources to include: author.'),
            ],
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            queryParameterValidationEnabled: false,
            processor: BookPatchProcessor::class,
            read: false,
            deserialize: false,
            validate: false,
        ),
    ],
)]
final class Book
{
    public ?string $id = null;
}
