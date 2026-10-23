<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PurchasePostProcessor;
use App\State\PurchaseProvider;

#[ApiResource(
    queryParameterValidationEnabled: false,
    shortName: 'purchases',
    parameters: [
        new HeaderParameter(key: 'Customer-Id', description: 'Id of the customer making the request.', required: true, schema: ['type' => 'integer']),
    ],
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            provider: PurchaseProvider::class,
            parameters: [
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1, 'default' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10], description: 'Items per page, from 1 to 50.'),
            ],
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            provider: PurchaseProvider::class,
        ),
        new Post(
            uriTemplate: '/purchases',
            processor: PurchasePostProcessor::class,
            input: false,
        ),
    ],
)]
final class Purchase
{
    public int $id;

    public string $createdAt;

    public int $totalCents;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $purchase = new self();
        $purchase->id = (int) $row['id'];
        $purchase->createdAt = (new \DateTimeImmutable($row['created_at']))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $purchase->totalCents = (int) $row['total_cents'];
        $purchase->lines = $row['lines'];

        return $purchase;
    }
}
