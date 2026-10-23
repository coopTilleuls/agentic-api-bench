<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\PurchaseCreateController;
use App\Controller\PurchaseGetController;
use App\Controller\PurchaseListController;

#[ApiResource(
    shortName: 'purchases',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(uriTemplate: '/purchases', controller: PurchaseListController::class, input: false, output: false),
        new Get(uriTemplate: '/purchases/{id}', controller: PurchaseGetController::class, input: false, output: false),
        new Post(uriTemplate: '/purchases', controller: PurchaseCreateController::class, input: false, output: false),
    ],
)]
final class Purchase
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
