<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;

#[ApiResource(
    shortName: 'purchases',
    operations: [
        new GetCollection(uriTemplate: '/purchases', provider: PurchaseProvider::class),
        new Get(uriTemplate: '/purchases/{id}', requirements: ['id' => '\d+'], provider: PurchaseProvider::class),
        new Post(
            uriTemplate: '/purchases',
            status: 201,
            input: false,
            processor: PurchaseProcessor::class,
        ),
    ],
    formats: ['jsonapi' => ['application/vnd.api+json']],
)]
final class Purchase
{
    public int $id;

    public string $createdAt = '';

    public int $totalCents = 0;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];
}
