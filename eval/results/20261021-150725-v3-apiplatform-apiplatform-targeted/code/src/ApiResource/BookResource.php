<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Factory\OpenApiFactory;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\BookProcessor;
use App\State\BookProvider;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            provider: BookProvider::class,
            paginationEnabled: false,
            parameters: [
                new QueryParameter(key: 'filter[title]', openApi: new OpenApiParameter(name: 'filter[title]', in: 'query', description: 'Title contains the text, case-insensitive.', schema: ['type' => 'string'])),
                new QueryParameter(key: 'filter[author]', openApi: new OpenApiParameter(name: 'filter[author]', in: 'query', description: 'Author name, exact.', schema: ['type' => 'string'])),
                new QueryParameter(key: 'filter[minPrice]', openApi: new OpenApiParameter(name: 'filter[minPrice]', in: 'query', description: 'Minimum price in cents, inclusive.', schema: ['type' => 'integer'])),
                new QueryParameter(key: 'filter[maxPrice]', openApi: new OpenApiParameter(name: 'filter[maxPrice]', in: 'query', description: 'Maximum price in cents, inclusive.', schema: ['type' => 'integer'])),
                new QueryParameter(key: 'filter[publishedAfter]', openApi: new OpenApiParameter(name: 'filter[publishedAfter]', in: 'query', description: 'Published on or after this date.', schema: ['type' => 'string', 'format' => 'date'])),
                new QueryParameter(key: 'filter[publishedBefore]', openApi: new OpenApiParameter(name: 'filter[publishedBefore]', in: 'query', description: 'Published on or before this date.', schema: ['type' => 'string', 'format' => 'date'])),
                new QueryParameter(key: 'filter[inStock]', openApi: new OpenApiParameter(name: 'filter[inStock]', in: 'query', description: 'true: stock greater than 0; false: stock 0.', schema: ['type' => 'boolean'])),
                new QueryParameter(key: 'sort', openApi: new OpenApiParameter(name: 'sort', in: 'query', description: 'Comma separated among priceCents, title, publishedAt; prefix with - for descending. Ties are broken by id ascending.', schema: ['type' => 'string'])),
                new QueryParameter(key: 'include', openApi: new OpenApiParameter(name: 'include', in: 'query', description: 'Related resources to include: author.', schema: ['type' => 'string'])),
                new QueryParameter(key: 'page[number]', openApi: new OpenApiParameter(name: 'page[number]', in: 'query', description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1])),
                new QueryParameter(key: 'page[size]', openApi: new OpenApiParameter(name: 'page[size]', in: 'query', description: 'Items per page, from 1 to 50, 10 by default.', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50])),
            ],
            openapi: new OpenApiOperation(responses: [
                '400' => new OpenApiResponse(description: 'A query parameter is invalid.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
        new Get(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            parameters: [
                new QueryParameter(key: 'include', openApi: new OpenApiParameter(name: 'include', in: 'query', description: 'Related resources to include: author.', schema: ['type' => 'string'])),
            ],
            openapi: new OpenApiOperation(responses: [
                '400' => new OpenApiResponse(description: 'The include parameter is invalid.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            processor: BookProcessor::class,
            inputFormats: ['jsonapi' => ['application/vnd.api+json']],
            openapi: new OpenApiOperation(responses: [
                '204' => new OpenApiResponse(description: 'The book is not published: nothing to return.'),
                '409' => new OpenApiResponse(description: 'The data id does not match the id of the URL.'),
                '415' => new OpenApiResponse(description: 'The content type is not application/vnd.api+json.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
    ],
)]
final class BookResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public int $id = 0;

    #[Assert\NotBlank]
    public string $title = '';

    #[Assert\PositiveOrZero]
    public int $priceCents = 0;

    #[ApiProperty(writable: false)]
    public ?string $publishedAt = null;

    #[Assert\PositiveOrZero]
    public int $stock = 0;

    #[ApiProperty(writable: false)]
    public ?AuthorResource $author = null;
}
