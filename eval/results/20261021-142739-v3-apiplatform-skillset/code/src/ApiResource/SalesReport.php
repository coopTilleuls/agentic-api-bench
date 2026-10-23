<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\State\SalesReportProvider;

#[ApiResource(
    shortName: 'reports',
    formats: [
        'csv' => ['text/csv'],
        'jsonapi' => ['application/vnd.api+json'],
    ],
    operations: [
        new Get(
            uriTemplate: '/reports/sales',
            provider: SalesReportProvider::class,
            queryParameterValidationEnabled: false,
            parameters: [
                new QueryParameter(key: 'from', required: true, description: 'First day of the period, YYYY-MM-DD (UTC, included).', schema: ['type' => 'string', 'format' => 'date']),
                new QueryParameter(key: 'to', required: true, description: 'Last day of the period, YYYY-MM-DD (UTC, included).', schema: ['type' => 'string', 'format' => 'date']),
            ],
        ),
    ],
)]
final class SalesReport
{
}
