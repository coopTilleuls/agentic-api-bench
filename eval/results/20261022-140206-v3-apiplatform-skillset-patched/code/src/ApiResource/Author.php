<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\AuthorProvider;

/**
 * An author, as served by /authors.
 */
#[ApiResource(
    shortName: 'Author',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            queryParameterValidationEnabled: false,
            provider: AuthorProvider::class,
            parameters: [
                'page[number]' => new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1. Default 1.'),
                'page[size]' => new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, from 1 to 50. Default 10.'),
            ],
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            queryParameterValidationEnabled: false,
            provider: AuthorProvider::class,
            parameters: [
                'include' => new QueryParameter(key: 'include', schema: ['type' => 'string'], description: 'Related resources to include: books.'),
            ],
        ),
    ],
)]
final class Author
{
    public ?string $id = null;
}
