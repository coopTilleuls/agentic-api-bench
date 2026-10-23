<?php

namespace App\Controller;

use App\Bookshop\Purchases;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseShow
{
    public function __construct(private readonly Purchases $purchases)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $customerId = $this->purchases->identify($request);
        QueryParams::include($request, []);

        $purchase = ctype_digit($id) ? $this->purchases->findFor($customerId, (int) $id) : null;
        if (null === $purchase) {
            throw JsonApiException::single(404, 'Not Found', sprintf('No purchase with id %s.', $id));
        }

        return JsonApi::response(['data' => $purchase]);
    }
}
