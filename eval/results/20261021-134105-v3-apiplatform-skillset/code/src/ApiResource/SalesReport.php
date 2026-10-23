<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Report\SalesReportController;

/**
 * Sales report of the books between two dates, downloaded as CSV (title, quantity, revenueCents).
 */
#[ApiResource(
    shortName: 'SalesReport',
    operations: [
        new GetCollection(uriTemplate: '/reports/sales', controller: SalesReportController::class, paginationEnabled: false),
    ],
)]
final class SalesReport
{
}
