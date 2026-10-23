<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\State\SalesReportProvider;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            uriTemplate: '/reports/sales',
            formats: ['csv' => ['text/csv', 'application/vnd.api+json']],
            paginationEnabled: false,
            provider: SalesReportProvider::class,
            hideHydraOperation: true,
            parameters: [
                'from' => new QueryParameter(required: true, constraints: [new Assert\NotBlank(), new Assert\Date()]),
                'to' => new QueryParameter(required: true, constraints: [new Assert\NotBlank(), new Assert\Date()]),
            ],
        ),
    ],
)]
final readonly class SalesReportRow
{
    public function __construct(
        public string $title,
        public int $quantity,
        public int $revenueCents,
    ) {
    }
}
