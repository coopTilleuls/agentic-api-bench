<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\BookPatchProcessor;
use App\State\BookProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'book')]
#[ApiResource(
    shortName: 'books',
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            provider: BookProvider::class,
            paginationEnabled: false,
            parameters: [
                'page[number]' => new QueryParameter(constraints: [], description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
                'page[size]' => new QueryParameter(constraints: [], description: 'Items per page, from 1 to 50.', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
                'sort' => new QueryParameter(constraints: [], description: 'Comma separated fields among priceCents, title and publishedAt, applied in order; prefix a field with - for descending. Ties are broken by id ascending.', schema: ['type' => 'string']),
                'include' => new QueryParameter(constraints: [], description: 'Related resources to include in the compound document: author.', schema: ['type' => 'string', 'enum' => ['author']]),
                'filter[title]' => new QueryParameter(constraints: [], description: 'Title contains the text, case-insensitive.', schema: ['type' => 'string']),
                'filter[author]' => new QueryParameter(constraints: [], description: 'Author name, exact.', schema: ['type' => 'string']),
                'filter[minPrice]' => new QueryParameter(constraints: [], description: 'Minimum price in cents, included.', schema: ['type' => 'integer']),
                'filter[maxPrice]' => new QueryParameter(constraints: [], description: 'Maximum price in cents, included.', schema: ['type' => 'integer']),
                'filter[publishedAfter]' => new QueryParameter(constraints: [], description: 'Published on or after this date.', schema: ['type' => 'string', 'format' => 'date']),
                'filter[publishedBefore]' => new QueryParameter(constraints: [], description: 'Published on or before this date.', schema: ['type' => 'string', 'format' => 'date']),
                'filter[inStock]' => new QueryParameter(constraints: [], description: 'true: stock greater than 0; false: stock 0.', schema: ['type' => 'boolean']),
            ],
            openapi: new Model\Operation(
                responses: [
                    '400' => new Model\Response(description: 'A query parameter is invalid.'),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            parameters: [
                'include' => new QueryParameter(constraints: [], description: 'Related resources to include in the compound document: author.', schema: ['type' => 'string', 'enum' => ['author']]),
            ],
            openapi: new Model\Operation(
                responses: [
                    '404' => new Model\Response(description: 'Unknown or unpublished book, or malformed id.'),
                ],
            ),
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            processor: BookPatchProcessor::class,
            input: false,
            openapi: new Model\Operation(
                requestBody: new Model\RequestBody(
                    description: 'A JSON:API document; only the attributes sent change.',
                    content: new \ArrayObject([
                        'application/vnd.api+json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['data'],
                                'properties' => [
                                    'data' => [
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
                                    ],
                                ],
                            ],
                        ],
                    ]),
                    required: true,
                ),
                responses: [
                    '200' => new Model\Response(description: 'The updated book.'),
                    '204' => new Model\Response(description: 'The book is unpublished: updated, nothing returned.'),
                    '404' => new Model\Response(description: 'Unknown book, or malformed id.'),
                    '409' => new Model\Response(description: 'The data id does not match the URL.'),
                    '415' => new Model\Response(description: 'The Content-Type is not application/vnd.api+json.'),
                    '422' => new Model\Response(description: 'Invalid attributes; nothing changed.'),
                ],
            ),
        ),
    ],
    formats: ['jsonapi' => ['application/vnd.api+json']],
)]
class Book
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    #[ApiProperty(identifier: true)]
    private ?int $id = null;

    #[ORM\Column(name: 'title', type: Types::TEXT)]
    private string $title = '';

    #[ORM\ManyToOne(targetEntity: Author::class, inversedBy: 'allBooks')]
    #[ORM\JoinColumn(name: 'author_id', referencedColumnName: 'id', nullable: false)]
    private Author $author;

    #[ORM\Column(name: 'published', type: Types::BOOLEAN)]
    #[ApiProperty(readable: false, writable: false)]
    private bool $published = false;

    #[ORM\Column(name: 'price_cents', type: Types::INTEGER)]
    private int $priceCents = 0;

    #[ORM\Column(name: 'published_at', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column(name: 'stock', type: Types::INTEGER)]
    private int $stock = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getAuthor(): Author
    {
        return $this->author;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function getPriceCents(): int
    {
        return $this->priceCents;
    }

    public function setPriceCents(int $priceCents): void
    {
        $this->priceCents = $priceCents;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): void
    {
        $this->stock = $stock;
    }
}
