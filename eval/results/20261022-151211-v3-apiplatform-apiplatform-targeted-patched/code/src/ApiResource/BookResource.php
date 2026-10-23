<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\BookProcessor;
use App\State\BookProvider;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            provider: BookProvider::class,
            paginationEnabled: false,
            parameters: [
                'filter[title]' => new QueryParameter(key: 'filter[title]', description: 'Title contains the text, case-insensitive.'),
                'filter[author]' => new QueryParameter(key: 'filter[author]', description: 'Author name, exact match.'),
                'filter[minPrice]' => new QueryParameter(key: 'filter[minPrice]', schema: ['type' => 'integer'], description: 'Minimum price in cents, inclusive.'),
                'filter[maxPrice]' => new QueryParameter(key: 'filter[maxPrice]', schema: ['type' => 'integer'], description: 'Maximum price in cents, inclusive.'),
                'filter[publishedAfter]' => new QueryParameter(key: 'filter[publishedAfter]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or after this date (YYYY-MM-DD).'),
                'filter[publishedBefore]' => new QueryParameter(key: 'filter[publishedBefore]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or before this date (YYYY-MM-DD).'),
                'filter[inStock]' => new QueryParameter(key: 'filter[inStock]', schema: ['type' => 'boolean'], description: 'true: stock greater than 0; false: stock 0.'),
                'sort' => new QueryParameter(key: 'sort', description: 'Comma-separated fields (priceCents, title, publishedAt), prefix with - for descending. Ties are broken by id.'),
                'include' => new QueryParameter(key: 'include', description: 'Related resources to include: author.'),
                'page[number]' => new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'default' => 1], description: 'Page number, from 1.'),
                'page[size]' => new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'default' => 10], description: 'Items per page, from 1 to 50.'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'A query parameter is invalid.'),
            ]),
        ),
        new Get(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            parameters: [
                'include' => new QueryParameter(key: 'include', description: 'Related resources to include: author.'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'A query parameter is invalid.'),
            ]),
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            processor: BookProcessor::class,
            openapi: new Model\Operation(responses: [
                '204' => new Model\Response(description: 'The book is not published: no body is returned.'),
                '409' => new Model\Response(description: 'The data id differs from the id of the path.'),
                '415' => new Model\Response(description: 'The Content-Type is not application/vnd.api+json.'),
            ]),
        ),
    ],
)]
final class BookResource
{
    #[ApiProperty(identifier: true)]
    #[Ignore]
    public string $id = '';

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
