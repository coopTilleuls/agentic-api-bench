<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\AuthorProvider;

#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            provider: AuthorProvider::class,
            parameters: [
                'page[number]' => new QueryParameter(
                    key: 'page[number]',
                    schema: ['type' => 'integer', 'default' => 1],
                    description: 'The page to return, from 1. A page past the last one is empty.',
                ),
                'page[size]' => new QueryParameter(
                    key: 'page[size]',
                    schema: ['type' => 'integer', 'default' => 10, 'description' => 'from 1 to 50'],
                    description: 'The number of items per page, from 1 to 50.',
                ),
                'include' => new QueryParameter(
                    key: 'include',
                    schema: ['type' => 'string', 'example' => 'books'],
                    description: 'Comma separated relationships to add to "included": books.',
                ),
            ],
            openapi: new OpenApiOperation(
                responses: ['400' => new OpenApiResponse(description: 'A query parameter is invalid.')],
            ),
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            requirements: ['id' => '\d+'],
            provider: AuthorProvider::class,
            parameters: [
                'include' => new QueryParameter(
                    key: 'include',
                    schema: ['type' => 'string', 'example' => 'books'],
                    description: 'Comma separated relationships to add to "included": books.',
                ),
            ],
            openapi: new OpenApiOperation(
                responses: [
                    '400' => new OpenApiResponse(description: 'A query parameter is invalid.'),
                    '404' => new OpenApiResponse(description: 'No author has this id.'),
                ],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
)]
final class Author
{
    /**
     * @param Book[]|null $books the published books, null when they are not part of the document
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public ?string $name = null,
        public int $publishedBooks = 0,
        public ?array $books = null,
    ) {
    }
}
