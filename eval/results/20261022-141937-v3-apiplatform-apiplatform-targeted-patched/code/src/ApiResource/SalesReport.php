<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use App\State\SalesReportProvider;

/**
 * The public sales report, downloaded as CSV. Not stored: the provider computes it from purchase lines.
 */
#[ApiResource(
    shortName: 'salesReports',
    operations: [
        new Get(
            uriTemplate: '/reports/sales',
            formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json']],
            provider: SalesReportProvider::class,
            parameters: [
                'from' => new QueryParameter(
                    required: true,
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'First day included, YYYY-MM-DD (UTC).',
                ),
                'to' => new QueryParameter(
                    required: true,
                    schema: ['type' => 'string', 'format' => 'date'],
                    description: 'Last day included, YYYY-MM-DD (UTC).',
                ),
            ],
        ),
    ],
)]
final class SalesReport
{
    #[ApiProperty(identifier: true)]
    public string $id = 'sales';
}
