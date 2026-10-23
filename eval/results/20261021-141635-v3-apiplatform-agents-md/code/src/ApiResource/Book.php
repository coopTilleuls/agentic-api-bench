<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\BookPatchProcessor;
use App\State\BookProvider;

#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            provider: BookProvider::class,
            parameters: [
                'filter[title]' => new QueryParameter(
                    key: 'filter[title]',
                    schema: ['type' => 'string'],
                    description: 'Books whose title contains the text, case-insensitive.',
                ),
                'filter[author]' => new QueryParameter(
                    key: 'filter[author]',
                    schema: ['type' => 'string'],
                    description: 'Books by the author with this exact name.',
                ),
                'filter[minPrice]' => new QueryParameter(
                    key: 'filter[minPrice]',
                    schema: ['type' => 'integer'],
                    description: 'Minimum price in cents, included.',
                ),
                'filter[maxPrice]' => new QueryParameter(
                    key: 'filter[maxPrice]',
                    schema: ['type' => 'integer'],
                    description: 'Maximum price in cents, included.',
                ),
                'filter[publishedAfter]' => new QueryParameter(
                    key: 'filter[publishedAfter]',
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Books published on or after this day (YYYY-MM-DD).',
                ),
                'filter[publishedBefore]' => new QueryParameter(
                    key: 'filter[publishedBefore]',
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Books published on or before this day (YYYY-MM-DD).',
                ),
                'filter[inStock]' => new QueryParameter(
                    key: 'filter[inStock]',
                    schema: ['type' => 'boolean'],
                    description: 'true: only books with stock greater than 0. false: only books with stock 0.',
                ),
                'sort' => new QueryParameter(
                    key: 'sort',
                    schema: ['type' => 'string', 'example' => '-priceCents,title'],
                    description: 'Comma separated fields to sort by, ascending unless prefixed with "-": priceCents, title, publishedAt. Ties are broken by id.',
                ),
                'include' => new QueryParameter(
                    key: 'include',
                    schema: ['type' => 'string', 'example' => 'author'],
                    description: 'Comma separated relationships to add to "included": author.',
                ),
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
            ],
            openapi: new OpenApiOperation(
                responses: ['400' => new OpenApiResponse(description: 'A query parameter is invalid.')],
            ),
        ),
        new Get(
            uriTemplate: '/books/{id}',
            requirements: ['id' => '\d+'],
            provider: BookProvider::class,
            parameters: [
                'include' => new QueryParameter(
                    key: 'include',
                    schema: ['type' => 'string', 'example' => 'author'],
                    description: 'Comma separated relationships to add to "included": author.',
                ),
            ],
            openapi: new OpenApiOperation(
                responses: [
                    '400' => new OpenApiResponse(description: 'A query parameter is invalid.'),
                    '404' => new OpenApiResponse(description: 'No published book has this id.'),
                ],
            ),
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            requirements: ['id' => '\d+'],
            provider: BookProvider::class,
            processor: BookPatchProcessor::class,
            deserialize: false,
            openapi: new OpenApiOperation(
                summary: 'Updates the title, price and stock of a book, published or not. Only the attributes sent change.',
                requestBody: new RequestBody(
                    required: true,
                    content: new \ArrayObject([
                        'application/vnd.api+json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['data'],
                                'properties' => ['data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['type' => 'string', 'enum' => ['books']],
                                        'id' => ['type' => 'string'],
                                        'attributes' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'title' => ['type' => 'string', 'minLength' => 1],
                                                'priceCents' => ['type' => 'integer', 'minimum' => 0],
                                                'stock' => ['type' => 'integer', 'minimum' => 0],
                                            ],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    '204' => new OpenApiResponse(description: 'The book is unpublished: no body.'),
                    '400' => new OpenApiResponse(description: 'The body is not a JSON document.'),
                    '404' => new OpenApiResponse(description: 'No book has this id.'),
                    '409' => new OpenApiResponse(description: 'The id or type of the document does not match the URL.'),
                    '415' => new OpenApiResponse(description: 'The Content-Type is not application/vnd.api+json.'),
                    '422' => new OpenApiResponse(description: 'A field is invalid: one error per field, nothing changed.'),
                ],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
)]
final class Book
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public string $title = '',
        public int $priceCents = 0,
        public ?string $publishedAt = null,
        public int $stock = 0,
        public ?Author $author = null,
    ) {
    }
}
