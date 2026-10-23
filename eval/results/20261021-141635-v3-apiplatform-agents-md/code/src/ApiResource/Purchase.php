<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;

#[ApiResource(
    shortName: 'purchases',
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            provider: PurchaseProvider::class,
            parameters: [
                'page[number]' => new QueryParameter(
                    key: 'page[number]',
                    schema: ['type' => 'integer', 'default' => 1],
                    description: 'The page to return, from 1. A page past the last one is empty.',
                ),
                'page[size]' => new QueryParameter(
                    key: 'page[size]',
                    schema: ['type' => 'integer', 'default' => 10, 'description' => 'from 1 to 50'],
                    description: 'The number of items per page, from 1 to 50.',
                ),
            ],
            openapi: new OpenApiOperation(
                parameters: [
                    new OpenApiParameter(name: 'Customer-Id', in: 'header', description: 'The id of the customer, who only sees their own purchases.', required: true, schema: ['type' => 'integer', 'minimum' => 1]),
                ],
                responses: [
                    '400' => new OpenApiResponse(description: 'A query parameter is invalid.'),
                    '401' => new OpenApiResponse(description: 'The Customer-Id header is missing, malformed or unknown.'),
                ],
            ),
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            requirements: ['id' => '\d+'],
            provider: PurchaseProvider::class,
            openapi: new OpenApiOperation(
                parameters: [
                    new OpenApiParameter(name: 'Customer-Id', in: 'header', description: 'The id of the customer, who only sees their own purchases.', required: true, schema: ['type' => 'integer', 'minimum' => 1]),
                ],
                responses: [
                    '401' => new OpenApiResponse(description: 'The Customer-Id header is missing, malformed or unknown.'),
                    '404' => new OpenApiResponse(description: 'No purchase of this customer has this id.'),
                ],
            ),
        ),
        new Post(
            uriTemplate: '/purchases',
            processor: PurchaseProcessor::class,
            deserialize: false,
            openapi: new OpenApiOperation(
                summary: 'Buys books for the customer: checks the stock, takes the payment, then stores the purchase.',
                parameters: [
                    new OpenApiParameter(name: 'Customer-Id', in: 'header', description: 'The id of the customer buying the books.', required: true, schema: ['type' => 'integer', 'minimum' => 1]),
                ],
                requestBody: new RequestBody(
                    required: true,
                    content: new \ArrayObject([
                        'application/vnd.api+json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['data'],
                                'properties' => ['data' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'type' => ['type' => 'string', 'enum' => ['purchases']],
                                        'attributes' => [
                                            'type' => 'object',
                                            'required' => ['lines'],
                                            'properties' => ['lines' => [
                                                'type' => 'array',
                                                'minItems' => 1,
                                                'items' => [
                                                    'type' => 'object',
                                                    'required' => ['bookId', 'quantity'],
                                                    'properties' => [
                                                        'bookId' => ['type' => 'integer'],
                                                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                                                    ],
                                                ],
                                            ]],
                                        ],
                                    ],
                                ]],
                            ],
                        ],
                    ]),
                ),
                responses: [
                    '201' => new OpenApiResponse(description: 'The purchase was stored; the Location header holds its URL.'),
                    '401' => new OpenApiResponse(description: 'The Customer-Id header is missing, malformed or unknown.'),
                    '402' => new OpenApiResponse(description: 'The payment was declined.'),
                    '409' => new OpenApiResponse(description: 'The stock is not enough for the quantity asked.'),
                    '415' => new OpenApiResponse(description: 'The Content-Type is not application/vnd.api+json.'),
                    '422' => new OpenApiResponse(description: 'A line is invalid: lines empty, unknown or unpublished book, quantity below 1.'),
                    '502' => new OpenApiResponse(description: 'The payment service is unreachable or failed.'),
                ],
            ),
        ),
    ],
    normalizationContext: ['skip_null_values' => false],
)]
final class Purchase
{
    /**
     * @param array<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> $lines
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public int $id,
        public string $createdAt = '',
        public int $totalCents = 0,
        public array $lines = [],
    ) {
    }
}
