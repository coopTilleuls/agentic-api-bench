<?php

namespace App\Controller\Purchase;

use App\Http\ApiException;
use App\Http\JsonApi;
use App\Http\PathId;
use App\Sales\CustomerAuthenticator;
use App\Sales\PurchaseDocument;
use App\Sales\PurchaseRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PurchaseGetController
{
    public function __construct(
        private readonly CustomerAuthenticator $customers,
        private readonly PurchaseRepository $purchases,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $customerId = $this->customers->customerId($request);
        $purchaseId = PathId::parse($id);
        $row = null === $purchaseId ? null : $this->purchases->find($customerId, $purchaseId);
        if (null === $row) {
            throw new ApiException(404, 'Purchase not found.');
        }

        $lines = $this->purchases->linesByPurchase([$purchaseId]);

        return JsonApi::document([
            'data' => PurchaseDocument::resource($row, $lines[$purchaseId] ?? [], JsonApi::baseUrl($request)),
        ]);
    }
}
