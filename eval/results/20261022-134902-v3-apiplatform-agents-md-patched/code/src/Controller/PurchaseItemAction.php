<?php

namespace App\Controller;

use App\Bookshop\CustomerIdentity;
use App\Bookshop\Presenter;
use App\Bookshop\Store;
use App\JsonApi\Document;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PurchaseItemAction
{
    public function __construct(
        private readonly Store $store,
        private readonly CustomerIdentity $customers,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $customerId = $this->customers->require($request);

        $purchaseId = QueryParams::pathId($id);
        $row = null === $purchaseId ? null : $this->store->findPurchase($customerId, $purchaseId);
        if (null === $row) {
            throw JsonApiException::create(404, 'Not found', 'No purchase with this id.');
        }
        QueryParams::include($request, []);

        $lines = $this->store->linesByPurchase([$purchaseId])[$purchaseId];

        return Document::response(Document::single(Presenter::purchase($request, $row, $lines)));
    }
}
