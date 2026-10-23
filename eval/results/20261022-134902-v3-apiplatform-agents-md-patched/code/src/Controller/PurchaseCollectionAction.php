<?php

namespace App\Controller;

use App\Bookshop\CustomerIdentity;
use App\Bookshop\Presenter;
use App\Bookshop\Store;
use App\JsonApi\Document;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PurchaseCollectionAction
{
    public function __construct(
        private readonly Store $store,
        private readonly CustomerIdentity $customers,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->customers->require($request);
        $page = QueryParams::page($request);
        QueryParams::include($request, []);

        $total = $this->store->countPurchases($customerId);
        $rows = $this->store->purchasePage($customerId, $page['size'], ($page['number'] - 1) * $page['size']);
        $lines = $this->store->linesByPurchase(array_map(static fn (array $row) => (int) $row['id'], $rows));

        $items = [];
        foreach ($rows as $row) {
            $items[] = Presenter::purchase($request, $row, $lines[(int) $row['id']]);
        }

        return Document::response(Document::collection($request, $items, $total, $page['number'], $page['size']));
    }
}
