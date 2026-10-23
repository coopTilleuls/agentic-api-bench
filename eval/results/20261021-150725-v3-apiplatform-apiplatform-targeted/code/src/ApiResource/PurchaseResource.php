<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Factory\OpenApiFactory;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;

/**
 * The purchases of the customer identified by the Customer-Id header.
 */
#[ApiResource(
    shortName: 'purchases',
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            provider: PurchaseProvider::class,
            paginationEnabled: false,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', openApi: new OpenApiParameter(name: 'Customer-Id', in: 'header', description: 'Id of the customer.', required: true, schema: ['type' => 'string'])),
                new QueryParameter(key: 'sort', openApi: new OpenApiParameter(name: 'sort', in: 'query', description: 'Comma separated among createdAt, totalCents; prefix with - for descending. Default -createdAt.', schema: ['type' => 'string'])),
                new QueryParameter(key: 'page[number]', openApi: new OpenApiParameter(name: 'page[number]', in: 'query', description: 'Page number, from 1.', schema: ['type' => 'integer', 'minimum' => 1])),
                new QueryParameter(key: 'page[size]', openApi: new OpenApiParameter(name: 'page[size]', in: 'query', description: 'Items per page, from 1 to 50, 10 by default.', schema: ['type' => 'integer', 'minimum' => 1, 'maximum' => 50])),
            ],
            openapi: new OpenApiOperation(responses: [
                '400' => new OpenApiResponse(description: 'A query parameter is invalid.'),
                '401' => new OpenApiResponse(description: 'The Customer-Id header is missing, unknown or malformed.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            provider: PurchaseProvider::class,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', openApi: new OpenApiParameter(name: 'Customer-Id', in: 'header', description: 'Id of the customer.', required: true, schema: ['type' => 'string'])),
            ],
            openapi: new OpenApiOperation(responses: [
                '401' => new OpenApiResponse(description: 'The Customer-Id header is missing, unknown or malformed.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
        new Post(
            uriTemplate: '/purchases',
            processor: PurchaseProcessor::class,
            parameters: [
                new HeaderParameter(key: 'Customer-Id', openApi: new OpenApiParameter(name: 'Customer-Id', in: 'header', description: 'Id of the customer.', required: true, schema: ['type' => 'string'])),
            ],
            openapi: new OpenApiOperation(responses: [
                '401' => new OpenApiResponse(description: 'The Customer-Id header is missing, unknown or malformed.'),
                '402' => new OpenApiResponse(description: 'The payment was declined. Nothing is stored.'),
                '409' => new OpenApiResponse(description: 'Not enough stock for a book. Nothing is stored.'),
                '415' => new OpenApiResponse(description: 'The content type is not application/vnd.api+json.'),
                '502' => new OpenApiResponse(description: 'The payment service failed or is unreachable. Nothing is stored.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
    ],
)]
final class PurchaseResource
{
    #[ApiProperty(identifier: true, writable: false)]
    public int $id = 0;

    #[ApiProperty(writable: false)]
    public string $createdAt = '';

    #[ApiProperty(writable: false)]
    public int $totalCents = 0;

    /** @var list<array<string, mixed>> */
    public array $lines = [];
}
