<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;

/**
 * A purchase of the customer identified by the Customer-Id header.
 */
#[ApiResource(
    shortName: 'Purchase',
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            queryParameterValidationEnabled: false,
            provider: PurchaseProvider::class,
            parameters: [
                'Customer-Id' => new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'], description: 'Id of the customer making the request.'),
                'page[number]' => new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'minimum' => 1], description: 'Page number, from 1. Default 1.'),
                'page[size]' => new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], description: 'Items per page, from 1 to 50. Default 10.'),
            ],
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            queryParameterValidationEnabled: false,
            provider: PurchaseProvider::class,
            parameters: [
                'Customer-Id' => new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'], description: 'Id of the customer making the request.'),
            ],
        ),
        new Post(
            uriTemplate: '/purchases',
            queryParameterValidationEnabled: false,
            processor: PurchaseProcessor::class,
            read: false,
            deserialize: false,
            validate: false,
            parameters: [
                'Customer-Id' => new HeaderParameter(key: 'Customer-Id', schema: ['type' => 'string', 'pattern' => '^[1-9][0-9]*$'], description: 'Id of the customer making the purchase.'),
            ],
        ),
    ],
)]
final class Purchase
{
    public ?string $id = null;
}
