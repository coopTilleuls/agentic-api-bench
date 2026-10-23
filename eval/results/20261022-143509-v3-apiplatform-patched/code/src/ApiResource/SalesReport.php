<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\Controller\Report\SalesReportController;

/**
 * The public sales report, a CSV download (`text/csv`) rather than a JSON:API resource.
 */
#[ApiResource(
    shortName: 'SalesReport',
    formats: ['csv' => ['text/csv']],
    operations: [
        new Get(
            uriTemplate: '/reports/sales',
            controller: SalesReportController::class,
            input: false,
            output: false,
            parameters: [
                new QueryParameter(key: 'from', required: true, schema: ['type' => 'string', 'format' => 'date'], description: 'First day of the period, inclusive (YYYY-MM-DD, UTC).'),
                new QueryParameter(key: 'to', required: true, schema: ['type' => 'string', 'format' => 'date'], description: 'Last day of the period, inclusive (YYYY-MM-DD, UTC).'),
            ],
            queryParameterValidationEnabled: false,
            name: 'reports_sales',
        ),
    ],
)]
final class SalesReport
{
    public string $title;

    public int $quantity;

    public int $revenueCents;
}
