<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\PurchaseCollectionAction;
use App\Controller\PurchaseCreateAction;
use App\Controller\PurchaseItemAction;

#[ApiResource(
    shortName: 'Purchase',
    operations: [
        new GetCollection(uriTemplate: '/purchases', controller: PurchaseCollectionAction::class),
        new Get(uriTemplate: '/purchases/{id}', controller: PurchaseItemAction::class),
        new Post(uriTemplate: '/purchases', controller: PurchaseCreateAction::class),
    ],
)]
final class Purchase
{
    public ?string $id = null;
    public ?string $createdAt = null;
    public int $totalCents = 0;
    public array $lines = [];
}
