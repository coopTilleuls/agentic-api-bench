<?php

namespace App\Controller\Api;

use App\Bookshop\CustomerResolver;
use App\Bookshop\Presenter;
use App\Bookshop\Purchases;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseItemController
{
    public function __construct(
        private readonly CustomerResolver $customers,
        private readonly Purchases $purchases,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $customerId = $this->customers->resolve($request);

        $purchaseId = JsonApi::pathId($id);
        ListParameters::include($request, []);

        $row = $this->purchases->find($customerId, $purchaseId) ?? throw JsonApiException::notFound();
        $lines = $this->purchases->linesOf([$purchaseId]);

        return JsonApi::response([
            'data' => Presenter::purchase($row, $lines[$purchaseId] ?? []),
            'links' => ['self' => "/purchases/$purchaseId"],
        ]);
    }
}
