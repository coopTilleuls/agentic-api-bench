<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\SalesReportProvider;


/**
 * The books sold between two days, as a CSV file. Public: covers all customers.
 */
#[ApiResource(
    shortName: 'SalesReport',
    operations: [
        new GetCollection(
            uriTemplate: '/reports/sales',
            queryParameterValidationEnabled: false,
            provider: SalesReportProvider::class,
            formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json'], 'json' => ['application/json']],
            parameters: [
                'from' => new QueryParameter(key: 'from', schema: ['type' => 'string', 'format' => 'date'], description: 'First day, included (YYYY-MM-DD, UTC).'),
                'to' => new QueryParameter(key: 'to', schema: ['type' => 'string', 'format' => 'date'], description: 'Last day, included (YYYY-MM-DD, UTC).'),
            ],
        ),
    ],
)]
final class SalesReport
{
    public ?string $id = null;
}
