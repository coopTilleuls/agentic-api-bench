<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use App\Entity\Book;
use App\JsonApi\JsonApiResourceInterface;
use App\State\BookPatchProcessor;
use App\State\BookProvider;

#[ApiResource(
    shortName: 'Book',
    operations: [
        new GetCollection(uriTemplate: '/books', provider: BookProvider::class, paginationEnabled: false),
        new Get(uriTemplate: '/books/{id}', provider: BookProvider::class),
        new Patch(uriTemplate: '/books/{id}', provider: BookProvider::class, processor: BookPatchProcessor::class, input: false),
    ],
)]
final class BookResource implements JsonApiResourceInterface
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;

    public string $title = '';

    public int $priceCents = 0;

    /** Publication date, YYYY-MM-DD. */
    public ?string $publishedAt = null;

    public int $stock = 0;

    public ?AuthorResource $author = null;

    public static function fromEntity(Book $book): self
    {
        $resource = new self();
        $resource->id = (int) $book->getId();
        $resource->title = $book->getTitle();
        $resource->priceCents = $book->getPriceCents();
        $resource->publishedAt = $book->getPublishedAt()?->format('Y-m-d');
        $resource->stock = $book->getStock();
        $resource->author = AuthorResource::fromEntity($book->getAuthor());

        return $resource;
    }

    public function getJsonApiType(): string
    {
        return 'books';
    }

    public function getJsonApiId(): string
    {
        return (string) $this->id;
    }

    public function getJsonApiAttributes(): array
    {
        return [
            'title' => $this->title,
            'priceCents' => $this->priceCents,
            'publishedAt' => $this->publishedAt,
            'stock' => $this->stock,
        ];
    }

    public function getJsonApiRelationships(): array
    {
        return ['author' => $this->author];
    }
}
