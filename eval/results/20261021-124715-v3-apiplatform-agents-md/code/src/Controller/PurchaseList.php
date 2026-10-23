<?php

namespace App\Controller;

use App\Bookshop\Purchases;
use App\JsonApi\JsonApi;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseList
{
    public function __construct(private readonly Purchases $purchases)
    {
    }

    public function __invoke(Request $request): Response
    {
        $customerId = $this->purchases->identify($request);
        QueryParams::include($request, []);
        QueryParams::sort($request, []);
        $page = QueryParams::page($request);

        $result = $this->purchases->listFor($customerId, ($page['number'] - 1) * $page['size'], $page['size']);

        return JsonApi::collection($request, $page, $result);
    }
}
