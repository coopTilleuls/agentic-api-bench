<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\Api\PurchaseCreateController;
use App\Controller\Api\PurchaseItemController;
use App\Controller\Api\PurchaseListController;

#[ApiResource(
    shortName: 'Purchase',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(uriTemplate: '/purchases', controller: PurchaseListController::class, output: false),
        new Get(uriTemplate: '/purchases/{id}', controller: PurchaseItemController::class, output: false),
        new Post(uriTemplate: '/purchases', controller: PurchaseCreateController::class, output: false),
    ],
)]
final class Purchase
{
}
