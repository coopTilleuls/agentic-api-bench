<?php

namespace App\Controller;

use App\Bookshop\Purchases;
use App\Http\ApiError;
use App\Http\Input;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PurchaseCreateController
{
    public function __construct(private readonly Purchases $purchases)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->purchases->customerId($request);
        $body = Input::jsonObject($request);

        $lines = $body['lines'] ?? null;
        if (!\is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw new ApiError(422, '"lines" must be a non-empty list.');
        }

        $validLines = [];
        foreach ($lines as $line) {
            $bookId = \is_array($line) ? ($line['bookId'] ?? null) : null;
            $quantity = \is_array($line) ? ($line['quantity'] ?? null) : null;
            if (!\is_int($bookId) || $bookId < 1) {
                throw new ApiError(422, 'Each line needs an integer "bookId".');
            }
            if (!\is_int($quantity) || $quantity < 1 || $quantity > Input::MAX_INT) {
                throw new ApiError(422, 'Each line needs an integer "quantity" of at least 1.');
            }
            $validLines[] = ['bookId' => $bookId, 'quantity' => $quantity];
        }

        return new JsonResponse($this->purchases->create($customerId, $validLines), 201);
    }
}
