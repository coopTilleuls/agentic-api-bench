<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\SalesReportProvider;

/**
 * The public sales report, downloaded as CSV.
 */
#[ApiResource(
    shortName: 'reports',
    operations: [
        new GetCollection(
            uriTemplate: '/reports/sales',
            provider: SalesReportProvider::class,
            formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json']],
            paginationEnabled: false,
            parameters: [
                'from' => new QueryParameter(constraints: [], description: 'First day of the period, included (UTC).', required: true, schema: ['type' => 'string', 'format' => 'date']),
                'to' => new QueryParameter(constraints: [], description: 'Last day of the period, included (UTC).', required: true, schema: ['type' => 'string', 'format' => 'date']),
            ],
            openapi: new Model\Operation(
                responses: [
                    '200' => new Model\Response(description: 'CSV file with the columns title, quantity, revenueCents.', content: new \ArrayObject([
                        'text/csv' => ['schema' => ['type' => 'string']],
                    ])),
                    '400' => new Model\Response(description: 'from or to is missing or malformed, or from is after to.'),
                ],
            ),
        ),
    ],
)]
final class SalesReport
{
}
