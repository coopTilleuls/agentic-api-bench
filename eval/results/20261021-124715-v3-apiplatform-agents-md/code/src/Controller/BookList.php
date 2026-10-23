<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\JsonApi\JsonApi;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookList
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = QueryParams::page($request);
        $result = $this->catalog->listBooks($request, ($page['number'] - 1) * $page['size'], $page['size']);

        return JsonApi::collection($request, $page, $result);
    }
}
