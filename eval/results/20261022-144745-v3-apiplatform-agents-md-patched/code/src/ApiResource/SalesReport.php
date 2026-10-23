<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Controller\Api\SalesReportController;

#[ApiResource(
    shortName: 'SalesReport',
    operations: [
        new Get(uriTemplate: '/reports/sales', controller: SalesReportController::class, output: false),
    ],
)]
final class SalesReport
{
}
