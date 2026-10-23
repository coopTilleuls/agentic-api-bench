<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\State\PurchaseCreateProcessor;
use App\State\PurchaseProvider;

#[ApiResource(
    shortName: 'purchases',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(uriTemplate: '/purchases', provider: PurchaseProvider::class),
        new Get(uriTemplate: '/purchases/{id}', provider: PurchaseProvider::class),
        new Post(uriTemplate: '/purchases', provider: PurchaseProvider::class, processor: PurchaseCreateProcessor::class, input: false),
    ],
)]
final class Purchase
{
    public int $id;

    public string $createdAt = '';

    public int $totalCents = 0;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];
}
