<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\HeaderParameter;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\PurchaseProcessor;
use App\State\PurchaseProvider;
use Symfony\Component\Serializer\Attribute\Ignore;

#[ApiResource(
    shortName: 'purchases',
    operations: [
        new GetCollection(
            uriTemplate: '/purchases',
            provider: PurchaseProvider::class,
            paginationEnabled: false,
            parameters: [
                'Customer-Id' => new HeaderParameter(key: 'Customer-Id', required: true, description: 'Id of the customer making the request.'),
                'sort' => new QueryParameter(key: 'sort', description: 'Comma-separated fields (createdAt, totalCents, id), prefix with - for descending. Default: most recent first.'),
                'page[number]' => new QueryParameter(key: 'page[number]', schema: ['type' => 'integer', 'default' => 1], description: 'Page number, from 1.'),
                'page[size]' => new QueryParameter(key: 'page[size]', schema: ['type' => 'integer', 'default' => 10], description: 'Items per page, from 1 to 50.'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'A query parameter is invalid.'),
                '401' => new Model\Response(description: 'The Customer-Id header is missing, malformed or unknown.'),
            ]),
        ),
        new Get(
            uriTemplate: '/purchases/{id}',
            provider: PurchaseProvider::class,
            parameters: [
                'Customer-Id' => new HeaderParameter(key: 'Customer-Id', required: true, description: 'Id of the customer making the request.'),
            ],
            openapi: new Model\Operation(responses: [
                '401' => new Model\Response(description: 'The Customer-Id header is missing, malformed or unknown.'),
            ]),
        ),
        new Post(
            uriTemplate: '/purchases',
            input: PurchaseInput::class,
            processor: PurchaseProcessor::class,
            parameters: [
                'Customer-Id' => new HeaderParameter(key: 'Customer-Id', required: true, description: 'Id of the customer making the request.'),
            ],
            openapi: new Model\Operation(responses: [
                '401' => new Model\Response(description: 'The Customer-Id header is missing, malformed or unknown.'),
                '402' => new Model\Response(description: 'The payment service declined the payment.'),
                '409' => new Model\Response(description: 'A book has not enough stock for the total quantity asked.'),
                '415' => new Model\Response(description: 'The Content-Type is not application/vnd.api+json.'),
                '502' => new Model\Response(description: 'The payment service is unreachable or failed.'),
            ]),
        ),
    ],
)]
final class PurchaseResource
{
    #[ApiProperty(identifier: true)]
    #[Ignore]
    public string $id = '';

    public string $createdAt = '';

    public int $totalCents = 0;

    /** @var list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> */
    public array $lines = [];
}
