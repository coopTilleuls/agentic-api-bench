<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\JsonApi\JsonApiResourceInterface;
use App\State\PurchaseCreateProcessor;
use App\State\PurchaseProvider;

#[ApiResource(
    shortName: 'Purchase',
    operations: [
        new GetCollection(uriTemplate: '/purchases', provider: PurchaseProvider::class, paginationEnabled: false),
        new Get(uriTemplate: '/purchases/{id}', provider: PurchaseProvider::class),
        new Post(uriTemplate: '/purchases', processor: PurchaseCreateProcessor::class, input: false),
    ],
)]
final class PurchaseResource implements JsonApiResourceInterface
{
    #[ApiProperty(identifier: true)]
    public int $id = 0;

    /** Creation timestamp, ISO 8601 in UTC. */
    public string $createdAt = '';

    public int $totalCents = 0;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];

    /**
     * @param list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> $lines
     */
    public static function create(int $id, \DateTimeImmutable $createdAt, int $totalCents, array $lines): self
    {
        $resource = new self();
        $resource->id = $id;
        $resource->createdAt = $createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $resource->totalCents = $totalCents;
        $resource->lines = $lines;

        return $resource;
    }

    public function getJsonApiType(): string
    {
        return 'purchases';
    }

    public function getJsonApiId(): string
    {
        return (string) $this->id;
    }

    public function getJsonApiAttributes(): array
    {
        return [
            'createdAt' => $this->createdAt,
            'totalCents' => $this->totalCents,
            'lines' => $this->lines,
        ];
    }

    public function getJsonApiRelationships(): array
    {
        return [];
    }
}
