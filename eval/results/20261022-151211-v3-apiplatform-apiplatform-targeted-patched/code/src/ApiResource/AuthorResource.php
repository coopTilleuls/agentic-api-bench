<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\AuthorProvider;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ApiResource(
    shortName: 'authors',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            provider: AuthorProvider::class,
            paginationEnabled: false,
            parameters: [
                'sort' => new QueryParameter(key: 'sort', description: 'Comma-separated fields (name, id), prefix with - for descending.'),
                'include' => new QueryParameter(key: 'include', description: 'Related resources to include: books.'),
                'page[number]' => new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'default' => 1], description: 'Page number, from 1.'),
                'page[size]' => new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'default' => 10], description: 'Items per page, from 1 to 50.'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'A query parameter is invalid.'),
            ]),
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            provider: AuthorProvider::class,
            parameters: [
                'include' => new QueryParameter(key: 'include', description: 'Related resources to include: books.'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'A query parameter is invalid.'),
            ]),
        ),
    ],
)]
final class AuthorResource
{
    #[ApiProperty(identifier: true)]
    #[Ignore]
    public string $id = '';

    public ?string $name = null;

    public int $publishedBooks = 0;

    /** @var list<BookResource> */
    public array $books = [];
}
