<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Controller\PurchaseCreate;
use App\Controller\PurchaseList;
use App\Controller\PurchaseShow;

#[ApiResource(
    shortName: 'Purchase',
    operations: [
        new GetCollection(uriTemplate: '/purchases', controller: PurchaseList::class),
        new Get(uriTemplate: '/purchases/{id}', controller: PurchaseShow::class),
        new Post(uriTemplate: '/purchases', controller: PurchaseCreate::class),
    ],
)]
final class Purchase
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
