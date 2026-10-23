<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\QueryParameter;
use App\Controller\Book\BookGetController;
use App\Controller\Book\BookListController;
use App\Controller\Book\BookPatchController;

/**
 * A published book (`books` resource). The controllers build the JSON:API documents.
 */
#[ApiResource(
    shortName: 'Book',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(
            uriTemplate: '/books',
            controller: BookListController::class,
            input: false,
            output: false,
            parameters: [
                new QueryParameter(key: 'filter[title]', schema: ['type' => 'string'], description: 'Books whose title contains the text, case-insensitive.'),
                new QueryParameter(key: 'filter[author]', schema: ['type' => 'string'], description: 'Books whose author name is exactly this text.'),
                new QueryParameter(key: 'filter[minPrice]', schema: ['type' => 'integer'], description: 'Price in cents, lower bound included.'),
                new QueryParameter(key: 'filter[maxPrice]', schema: ['type' => 'integer'], description: 'Price in cents, upper bound included.'),
                new QueryParameter(key: 'filter[publishedAfter]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or after this date (YYYY-MM-DD).'),
                new QueryParameter(key: 'filter[publishedBefore]', schema: ['type' => 'string', 'format' => 'date'], description: 'Published on or before this date (YYYY-MM-DD).'),
                new QueryParameter(key: 'filter[inStock]', schema: ['type' => 'boolean'], description: 'true: stock greater than 0; false: stock 0.'),
                new QueryParameter(key: 'sort', schema: ['type' => 'string'], description: 'Comma separated fields among priceCents, title, publishedAt; prefix with - for descending. Ties are broken by id ascending.'),
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['author']], description: 'Related resources to include.'),
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, from 1 to 50 (default 10).'),
            ],
            queryParameterValidationEnabled: false,
            name: 'books_list',
        ),
        new Get(
            uriTemplate: '/books/{id}',
            controller: BookGetController::class,
            input: false,
            output: false,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['author']], description: 'Related resources to include.'),
            ],
            queryParameterValidationEnabled: false,
            name: 'books_get',
        ),
        new Patch(
            uriTemplate: '/books/{id}',
            controller: BookPatchController::class,
            input: false,
            output: false,
            queryParameterValidationEnabled: false,
            name: 'books_patch',
        ),
    ],
)]
final class Book
{
    public string $id;

    public string $title;

    public int $priceCents;

    public ?string $publishedAt = null;

    public int $stock;
}
