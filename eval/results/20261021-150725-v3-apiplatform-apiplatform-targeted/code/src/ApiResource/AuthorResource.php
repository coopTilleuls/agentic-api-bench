<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Factory\OpenApiFactory;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\AuthorProvider;

#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            provider: AuthorProvider::class,
            paginationEnabled: false,
            parameters: [
                new QueryParameter(key: 'page[number]', openApi: new OpenApiParameter(name: 'page[number]', in: 'query', description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1])),
                new QueryParameter(key: 'page[size]', openApi: new OpenApiParameter(name: 'page[size]', in: 'query', description: 'Items per page, from 1 to 50, 10 by default.', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50])),
            ],
            openapi: new OpenApiOperation(responses: [
                '400' => new OpenApiResponse(description: 'A query parameter is invalid.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            provider: AuthorProvider::class,
            parameters: [
                new QueryParameter(key: 'include', openApi: new OpenApiParameter(name: 'include', in: 'query', description: 'Related resources to include: books.', schema: ['type' => 'string'])),
            ],
            openapi: new OpenApiOperation(responses: [
                '400' => new OpenApiResponse(description: 'The include parameter is invalid.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
    ],
)]
final class AuthorResource
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;

    public ?string $name = null;

    public int $publishedBooks = 0;

    /** @var list<BookResource> */
    public array $books = [];
}
