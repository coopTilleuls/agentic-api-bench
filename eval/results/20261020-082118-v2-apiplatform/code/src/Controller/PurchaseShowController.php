<?php

namespace App\Controller;

use App\Bookshop\Purchases;
use App\Http\ApiError;
use App\Http\Input;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PurchaseShowController
{
    public function __construct(private readonly Purchases $purchases)
    {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $customerId = $this->purchases->customerId($request);
        $purchase = $this->purchases->find($customerId, Input::pathId($id));
        if (null === $purchase) {
            throw new ApiError(404, 'Purchase not found.');
        }

        return new JsonResponse($purchase);
    }
}
