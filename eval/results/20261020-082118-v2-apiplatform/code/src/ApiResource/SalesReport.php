<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Controller\SalesReportController;

#[ApiResource(
    shortName: 'SalesReport',
    operations: [
        new Get(uriTemplate: '/reports/sales', controller: SalesReportController::class, read: false, deserialize: false, validate: false, write: false, serialize: false),
    ],
)]
final class SalesReport
{
}
