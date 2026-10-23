<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\Http\Input;
use App\Http\Responses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthorsListController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        ['page' => $page, 'perPage' => $perPage] = Input::pagination($request);

        return Responses::list($this->catalog->authors($page, $perPage), $page, $perPage);
    }
}
