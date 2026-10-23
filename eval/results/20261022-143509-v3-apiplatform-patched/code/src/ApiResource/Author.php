<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Controller\Author\AuthorGetController;
use App\Controller\Author\AuthorListController;

/**
 * An author (`authors` resource). The controllers build the JSON:API documents.
 */
#[ApiResource(
    shortName: 'Author',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(
            uriTemplate: '/authors',
            controller: AuthorListController::class,
            input: false,
            output: false,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['books']], description: 'Related resources to include.'),
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, from 1 to 50 (default 10).'),
            ],
            queryParameterValidationEnabled: false,
            name: 'authors_list',
        ),
        new Get(
            uriTemplate: '/authors/{id}',
            controller: AuthorGetController::class,
            input: false,
            output: false,
            parameters: [
                new QueryParameter(key: 'include', schema: ['type' => 'string', 'enum' => ['books']], description: 'Related resources to include.'),
            ],
            queryParameterValidationEnabled: false,
            name: 'authors_get',
        ),
    ],
)]
final class Author
{
    public string $id;

    public ?string $name = null;

    public int $publishedBooks;
}
