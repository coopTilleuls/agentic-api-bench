<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Controller\SalesReportShow;

#[ApiResource(
    shortName: 'SalesReport',
    operations: [
        new Get(uriTemplate: '/reports/sales', controller: SalesReportShow::class, formats: ['csv' => ['text/csv'], 'json' => ['application/json', 'application/vnd.api+json']]),
    ],
)]
final class SalesReport
{
    #[ApiProperty(identifier: true)]
    public int $id;
}
