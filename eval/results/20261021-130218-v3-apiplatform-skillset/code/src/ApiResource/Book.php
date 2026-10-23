<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\State\BookPatchProcessor;
use App\State\BookProvider;

#[ApiResource(
    queryParameterValidationEnabled: false,
    shortName: 'books',
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            provider: BookProvider::class,
            parameters: [
                new QueryParameter(key: 'filter[title]', schema: ['type' => 'string'], description: 'Title contains the text, case-insensitive.'),
                new QueryParameter(key: 'filter[author]', schema: ['type' => 'string'], description: 'Author name, exact match.'),
                new QueryParameter(key: 'filter[minPrice]', schema: ['type' => 'integer'], description: 'Minimum price in cents, inclusive.'),
                new QueryParameter(key: 'filter[maxPrice]', schema: ['type' => 'integer'], description: 'Maximum price in cents, inclusive.'),
                new QueryParameter(key: 'filter[publishedAfter]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or after this date (YYYY-MM-DD).'),
                new QueryParameter(key: 'filter[publishedBefore]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or before this date (YYYY-MM-DD).'),
                new QueryParameter(key: 'filter[inStock]', schema: ['type' => 'boolean'], description: 'true: stock greater than 0; false: stock equal to 0.'),
                new QueryParameter(key: 'sort', schema: ['type' => 'string'], description: 'Comma-separated fields among priceCents, title, publishedAt; prefix a field with "-" for descending order. Ties are broken by id ascending.'),
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['author']], description: 'Related resources to include: author.'),
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10], description: 'Items per page, from 1 to 50.'),
            ],
        ),
        new Get(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['author']], description: 'Related resources to include: author.'),
            ],
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            provider: BookProvider::class,
            processor: BookPatchProcessor::class,
            input: false,
        ),
    ],
)]
final class Book
{
    public int $id;

    public string $title;

    public int $priceCents;

    public ?string $publishedAt = null;

    public int $stock;

    public ?Author $author = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $author = new Author();
        $author->id = (int) $row['author_id'];
        $author->name = $row['author_name'];

        $book = new self();
        $book->id = (int) $row['id'];
        $book->title = $row['title'];
        $book->priceCents = (int) $row['price_cents'];
        $book->publishedAt = $row['published_at'];
        $book->stock = (int) $row['stock'];
        $book->author = $author;

        return $book;
    }
}
