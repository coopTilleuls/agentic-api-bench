<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\Author;
use App\JsonApi\JsonApiResourceInterface;
use App\State\AuthorProvider;

#[ApiResource(
    shortName: 'Author',
    operations: [
        new GetCollection(uriTemplate: '/authors', provider: AuthorProvider::class, paginationEnabled: false),
        new Get(uriTemplate: '/authors/{id}', provider: AuthorProvider::class),
    ],
)]
final class AuthorResource implements JsonApiResourceInterface
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;

    public ?string $name = null;

    /** Number of published books, not computed for included authors. */
    public ?int $publishedBooks = null;

    /** @var list<BookResource>|null Published books, only loaded for an author item and for ?include=books. */
    public ?array $books = null;

    public static function fromEntity(Author $author, ?int $publishedBooks = null): self
    {
        $resource = new self();
        $resource->id = (int) $author->getId();
        $resource->name = $author->getName();
        $resource->publishedBooks = $publishedBooks;

        return $resource;
    }

    public function getJsonApiType(): string
    {
        return 'authors';
    }

    public function getJsonApiId(): string
    {
        return (string) $this->id;
    }

    public function getJsonApiAttributes(): array
    {
        $attributes = ['name' => $this->name];
        if (null !== $this->publishedBooks) {
            $attributes['publishedBooks'] = $this->publishedBooks;
        }

        return $attributes;
    }

    public function getJsonApiRelationships(): array
    {
        return null === $this->books ? [] : ['books' => $this->books];
    }
}
