<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\PurchaseCreateController;
use App\Controller\PurchaseRefundController;
use App\Controller\PurchaseShowController;
use App\Controller\PurchasesListController;

#[ApiResource(
    shortName: 'Purchase',
    operations: [
        new GetCollection(uriTemplate: '/purchases', controller: PurchasesListController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
        new Get(uriTemplate: '/purchases/{id}', controller: PurchaseShowController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
        new Post(uriTemplate: '/purchases', controller: PurchaseCreateController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
        new Post(uriTemplate: '/purchases/{id}/refund', controller: PurchaseRefundController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
    ],
)]
final class Purchase
{
}
