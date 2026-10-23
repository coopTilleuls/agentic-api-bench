<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\UrlGeneratorInterface;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\ApiResource\Input\PurchaseCreate;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;

#[ApiResource(
    shortName: 'purchases',
    formats: ['jsonapi' => ['application/vnd.api+json']],
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            queryParameterValidationEnabled: false,
            urlGenerationStrategy: UrlGeneratorInterface::ABS_URL,
            provider: PurchaseProvider::class,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string'], description: 'Identifies the customer. Required.', required: true),
                new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1.'),
                new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, 1 to 50 (default 10).'),
            ],
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            queryParameterValidationEnabled: false,
            provider: PurchaseProvider::class,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string'], description: 'Identifies the customer. Required.', required: true),
            ],
        ),
        new Post(
            uriTemplate: '/purchases',
            queryParameterValidationEnabled: false,
            provider: PurchaseProvider::class,
            processor: PurchaseProcessor::class,
            input: PurchaseCreate::class,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string'], description: 'Identifies the customer. Required.', required: true),
            ],
        ),
    ],
)]
final class Purchase
{
    public int $id = 0;
    public string $createdAt = '';
    public int $totalCents = 0;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];
}
