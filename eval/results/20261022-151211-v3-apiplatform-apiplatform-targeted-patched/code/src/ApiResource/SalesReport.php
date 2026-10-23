<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model;
use App\State\SalesReportProvider;

/**
 * CSV download of the books sold between two days.
 */
#[ApiResource(
    shortName: 'reports',
    operations: [
        new GetCollection(
            uriTemplate: '/reports/sales',
            provider: SalesReportProvider::class,
            paginationEnabled: false,
            formats: ['csv' => ['text/csv'], 'jsonapi' => ['application/vnd.api+json']],
            parameters: [
                'from' => new QueryParameter(key: 'from', schema: ['type' => 'string', 'format' => 'date'], description: 'First day, inclusive (UTC). Required.'),
                'to' => new QueryParameter(key: 'to', schema: ['type' => 'string', 'format' => 'date'], description: 'Last day, inclusive (UTC). Required.'),
            ],
            openapi: new Model\Operation(responses: [
                '400' => new Model\Response(description: 'from or to is missing or malformed, or from is after to.'),
            ]),
        ),
    ],
)]
final class SalesReport
{
}
