<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\SalesReportProvider;

/**
 * The sales report: a CSV download, not a JSON:API resource.
 */
#[ApiResource(
    shortName: 'reports',
    operations: [
        new GetCollection(
            uriTemplate: '/reports/sales',
            formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json']],
            paginationEnabled: false,
            provider: SalesReportProvider::class,
        ),
    ],
)]
final class SalesReport
{
}
