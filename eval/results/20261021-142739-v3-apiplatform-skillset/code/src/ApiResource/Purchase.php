<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ApiResource(
    shortName: 'purchases',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            provider: PurchaseProvider::class,
            queryParameterValidationEnabled: false,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', description: 'Id of the customer making the request.', required: true, schema: ['type' => 'integer']),
                new QueryParameter(key: 'page[number]', description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1]),
                new QueryParameter(key: 'page[size]', description: 'Items per page, from 1 to 50 (default 10).', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50]),
            ],
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            requirements: ['id' => '\d+'],
            provider: PurchaseProvider::class,
            queryParameterValidationEnabled: false,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', description: 'Id of the customer making the request.', required: true, schema: ['type' => 'integer']),
            ],
        ),
        new Post(
            uriTemplate: '/purchases',
            processor: PurchaseProcessor::class,
            queryParameterValidationEnabled: false,
            input: false,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', description: 'Id of the customer making the purchase.', required: true, schema: ['type' => 'integer']),
            ],
        ),
    ],
)]
final class Purchase
{
    #[ApiProperty(identifier: true)]
    #[Ignore]
    public int $id = 0;

    public string $createdAt = '';

    public int $totalCents = 0;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];
}
