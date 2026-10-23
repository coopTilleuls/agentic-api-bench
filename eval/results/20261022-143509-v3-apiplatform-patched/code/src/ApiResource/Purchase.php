<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\Controller\Purchase\PurchaseCreateController;
use App\Controller\Purchase\PurchaseGetController;
use App\Controller\Purchase\PurchaseListController;

/**
 * A purchase made by the customer named in the Customer-Id header (`purchases` resource).
 */
#[ApiResource(
    shortName: 'Purchase',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            controller: PurchaseListController::class,
            input: false,
            output: false,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string'], description: 'Id of the customer. Required.', required: true),
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, from 1 to 50 (default 10).'),
            ],
            queryParameterValidationEnabled: false,
            name: 'purchases_list',
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            controller: PurchaseGetController::class,
            input: false,
            output: false,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string'], description: 'Id of the customer. Required.', required: true),
            ],
            queryParameterValidationEnabled: false,
            name: 'purchases_get',
        ),
        new Post(
            uriTemplate: '/purchases',
            controller: PurchaseCreateController::class,
            input: false,
            output: false,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string'], description: 'Id of the customer. Required.', required: true),
            ],
            queryParameterValidationEnabled: false,
            name: 'purchases_create',
        ),
    ],
)]
final class Purchase
{
    public string $id;

    public string $createdAt;

    public int $totalCents;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];
}
