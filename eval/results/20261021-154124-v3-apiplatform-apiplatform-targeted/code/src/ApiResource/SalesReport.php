<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\Model\Parameter as OpenApiParameter;
use App\State\SalesReportProvider;

/**
 * Public CSV report of the books sold between two days (UTC, both included).
 *
 * from and to are required, but the provider answers the missing or malformed
 * ones with a 400 JSON:API error, so they are only declared to OpenAPI here.
 */
#[ApiResource(
    shortName: 'reports',
    operations: [
        new GetCollection(
            uriTemplate: '/reports/sales',
            formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json']],
            provider: SalesReportProvider::class,
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'from or to is missing or malformed, or from is after to'),
            ]),
            parameters: [
                'from' => new QueryParameter(
                    openApi: new OpenApiParameter(name: 'from', in: 'query', description: 'First day, YYYY-MM-DD', required: true, schema: ['type' => 'string', 'format' => 'date']),
                ),
                'to' => new QueryParameter(
                    openApi: new OpenApiParameter(name: 'to', in: 'query', description: 'Last day, YYYY-MM-DD', required: true, schema: ['type' => 'string', 'format' => 'date']),
                ),
            ],
        ),
    ],
)]
final class SalesReport
{
}
