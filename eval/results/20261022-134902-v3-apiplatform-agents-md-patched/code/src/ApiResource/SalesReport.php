<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\Controller\SalesReportAction;

#[ApiResource(
    shortName: 'SalesReport',
    operations: [
        new GetCollection(uriTemplate: '/reports/sales', controller: SalesReportAction::class, name: 'sales_report'),
    ],
)]
final class SalesReport
{
}
