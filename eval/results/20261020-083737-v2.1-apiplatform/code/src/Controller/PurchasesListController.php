<?php

namespace App\Controller;

use App\Bookshop\Purchases;
use App\Http\Input;
use App\Http\Responses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PurchasesListController
{
    public function __construct(private readonly Purchases $purchases)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->purchases->customerId($request);
        ['page' => $page, 'perPage' => $perPage] = Input::pagination($request);

        return Responses::list($this->purchases->list($customerId, $page, $perPage), $page, $perPage);
    }
}
