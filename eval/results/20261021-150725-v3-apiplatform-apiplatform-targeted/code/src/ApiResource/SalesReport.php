<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Factory\OpenApiFactory;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\State\SalesReportProvider;

/**
 * The sales report is a CSV download, not a JSON:API collection: the provider answers the file itself.
 */
#[ApiResource(
    shortName: 'salesReports',
    operations: [
        new GetCollection(
            uriTemplate: '/reports/sales',
            formats: ['jsonapi' => 'application/vnd.api+json', 'csv' => 'text/csv'],
            provider: SalesReportProvider::class,
            paginationEnabled: false,
            parameters: [
                new QueryParameter(key: 'from', openApi: new OpenApiParameter(name: 'from', in: 'query', required: true, description: 'First day of the period, YYYY-MM-DD (UTC), included.', schema: ['type' => 'string', 'format' => 'date'])),
                new QueryParameter(key: 'to', openApi: new OpenApiParameter(name: 'to', in: 'query', required: true, description: 'Last day of the period, YYYY-MM-DD (UTC), included.', schema: ['type' => 'string', 'format' => 'date'])),
            ],
            openapi: new OpenApiOperation(responses: [
                '200' => new OpenApiResponse(description: 'CSV file of the books sold in the period.', content: new \ArrayObject([
                    'text/csv' => ['schema' => ['type' => 'string', 'example' => "title,quantity,revenueCents\r\nDune,3,6000\r\n"]],
                ])),
                '400' => new OpenApiResponse(description: 'from or to is missing or malformed, or from is after to.'),
            ]),
            extraProperties: [OpenApiFactory::OVERRIDE_OPENAPI_RESPONSES => true],
        ),
    ],
)]
final class SalesReport
{
}
