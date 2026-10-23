<?php

namespace App\Controller\Api;

use App\Bookshop\CustomerResolver;
use App\Bookshop\Presenter;
use App\Bookshop\Purchases;
use App\JsonApi\JsonApi;
use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseListController
{
    public function __construct(
        private readonly CustomerResolver $customers,
        private readonly Purchases $purchases,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $customerId = $this->customers->resolve($request);

        $page = ListParameters::page($request);
        ListParameters::include($request, []);
        ListParameters::sort($request, []);

        $result = $this->purchases->forCustomer($customerId, $page['number'], $page['size']);
        $purchaseIds = array_map(static fn (array $row): int => (int) $row['id'], $result['rows']);
        $lines = $this->purchases->linesOf($purchaseIds);

        $data = array_map(
            static fn (array $row): array => Presenter::purchase($row, $lines[(int) $row['id']] ?? []),
            $result['rows'],
        );

        return JsonApi::collection($request, $page, $result['total'], $data);
    }
}
