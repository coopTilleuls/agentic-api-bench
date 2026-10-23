<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Controller\SalesReportController;

#[ApiResource(
    shortName: 'reports',
    formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json']],
    operations: [
        new Get(uriTemplate: '/reports/sales', controller: SalesReportController::class, input: false, output: false),
    ],
)]
final class SalesReport
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
