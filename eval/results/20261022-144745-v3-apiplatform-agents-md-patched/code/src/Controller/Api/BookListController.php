<?php

namespace App\Controller\Api;

use App\Bookshop\BookFilters;
use App\Bookshop\Catalog;
use App\Bookshop\Presenter;
use App\JsonApi\JsonApi;
use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookListController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = ListParameters::page($request);
        $include = ListParameters::include($request, ['author']);
        $sort = ListParameters::sort($request, Catalog::BOOK_SORT);
        $criteria = BookFilters::fromRequest($request);

        $result = $this->catalog->books($criteria, $sort, $page['number'], $page['size']);
        $data = array_map(Presenter::book(...), $result['rows']);

        $included = null;
        if ([] !== $include) {
            $authorIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['author_id'], $result['rows'])));
            $included = array_map(Presenter::author(...), $this->catalog->authorsByIds($authorIds));
        }

        return JsonApi::collection($request, $page, $result['total'], $data, $included);
    }
}
