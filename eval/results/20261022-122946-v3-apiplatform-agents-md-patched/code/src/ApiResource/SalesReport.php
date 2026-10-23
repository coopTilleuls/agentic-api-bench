<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Controller\SalesReportController;

#[ApiResource(
    shortName: 'reports',
    formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json']],
    operations: [
        new Get(uriTemplate: '/reports/sales', controller: SalesReportController::class, name: 'sales_report'),
    ],
)]
final class SalesReport
{
    public string $id = 'sales';
}
