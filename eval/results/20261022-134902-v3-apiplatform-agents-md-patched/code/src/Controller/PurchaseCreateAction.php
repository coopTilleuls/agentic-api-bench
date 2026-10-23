<?php

namespace App\Controller;

use App\Bookshop\CustomerIdentity;
use App\Bookshop\Presenter;
use App\Bookshop\PurchaseService;
use App\Bookshop\Store;
use App\JsonApi\Body;
use App\JsonApi\Document;
use App\JsonApi\JsonApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseCreateAction
{
    public function __construct(
        private readonly Store $store,
        private readonly CustomerIdentity $customers,
        private readonly PurchaseService $purchases,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $customerId = $this->customers->require($request);
        Body::requireMediaType($request);

        $data = Body::data($request);
        if (($data['type'] ?? null) !== 'purchases') {
            throw JsonApiException::create(409, 'Conflict', 'The data type must be purchases.', '/data/type');
        }

        $attributes = is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $lines = $attributes['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines)) {
            throw JsonApiException::create(422, 'Invalid lines', 'lines must be a list of purchase lines.', '/data/attributes/lines');
        }

        $purchaseId = $this->purchases->buy($customerId, $lines);

        $row = $this->store->findPurchase($customerId, $purchaseId);
        $purchase = Presenter::purchase($request, $row, $this->store->linesByPurchase([$purchaseId])[$purchaseId]);

        return Document::response(
            Document::single($purchase),
            201,
            ['Location' => Document::baseUrl($request).'/purchases/'.$purchaseId],
        );
    }
}
