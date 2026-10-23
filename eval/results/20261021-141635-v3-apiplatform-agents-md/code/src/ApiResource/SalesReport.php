<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Report\SalesReportController;

/**
 * The public sales report: a CSV file of the books sold between two days.
 */
#[ApiResource(
    shortName: 'sales-reports',
    operations: [
        new Get(
            uriTemplate: '/reports/sales',
            controller: SalesReportController::class,
            read: false,
            deserialize: false,
            serialize: false,
            parameters: [
                'from' => new QueryParameter(
                    key: 'from',
                    schema: ['type' => 'string', 'format' => 'date', 'example' => '2026-01-01'],
                    description: 'First day of the report, included (YYYY-MM-DD, UTC).',
                    required: true,
                ),
                'to' => new QueryParameter(
                    key: 'to',
                    schema: ['type' => 'string', 'format' => 'date', 'example' => '2026-01-31'],
                    description: 'Last day of the report, included (YYYY-MM-DD, UTC).',
                    required: true,
                ),
            ],
            openapi: new OpenApiOperation(
                summary: 'Downloads the books sold between two days, as CSV (text/csv, RFC 4180).',
                responses: [
                    '200' => new OpenApiResponse(description: 'CSV file with the columns title, quantity and revenueCents.'),
                    '400' => new OpenApiResponse(description: 'from or to is missing or malformed, or from is after to.'),
                ],
            ),
        ),
    ],
)]
final class SalesReport
{
}
