<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\AuthorProvider;

#[ApiResource(
    queryParameterValidationEnabled: false,
    shortName: 'authors',
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            provider: AuthorProvider::class,
            parameters: [
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10], description: 'Items per page, from 1 to 50.'),
            ],
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            provider: AuthorProvider::class,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['books']], description: 'Related resources to include: books.'),
            ],
        ),
    ],
)]
final class Author
{
    public int $id;

    public ?string $name = null;

    public int $publishedBooks = 0;

    /** @var list<Book> */
    public array $books = [];

    /**
     * @param array{id: int|string, name: ?string, published_books?: int|string} $row
     */
    public static function fromRow(array $row): self
    {
        $author = new self();
        $author->id = (int) $row['id'];
        $author->name = $row['name'];
        $author->publishedBooks = (int) ($row['published_books'] ?? 0);

        return $author;
    }
}
