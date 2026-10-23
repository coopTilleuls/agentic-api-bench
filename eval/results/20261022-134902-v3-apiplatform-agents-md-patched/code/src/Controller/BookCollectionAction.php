<?php

namespace App\Controller;

use App\Bookshop\Presenter;
use App\Bookshop\Store;
use App\JsonApi\Document;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class BookCollectionAction
{
    public function __construct(private readonly Store $store)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $page = QueryParams::page($request);
        $include = QueryParams::include($request, ['author']);
        $sorts = QueryParams::sort($request, array_keys(Store::BOOK_SORT));
        $filters = [
            'title' => QueryParams::filter($request, 'title'),
            'author' => QueryParams::filter($request, 'author'),
            'minPrice' => QueryParams::integerFilter($request, 'minPrice'),
            'maxPrice' => QueryParams::integerFilter($request, 'maxPrice'),
            'publishedAfter' => QueryParams::dateFilter($request, 'publishedAfter'),
            'publishedBefore' => QueryParams::dateFilter($request, 'publishedBefore'),
            'inStock' => QueryParams::booleanFilter($request, 'inStock'),
        ];

        $total = $this->store->countBooks($filters);
        $rows = $this->store->books($filters, $sorts, $page['size'], ($page['number'] - 1) * $page['size']);

        $items = [];
        foreach ($rows as $row) {
            $items[] = Presenter::book($request, $row);
        }

        $included = [];
        if (in_array('author', $include, true)) {
            $authorIds = array_values(array_unique(array_map(static fn (array $row) => (int) $row['author_id'], $rows)));
            foreach ($this->store->authorsByIds($authorIds) as $author) {
                $included[] = Presenter::authorIncluded($request, $author);
            }
        }

        return Document::response(Document::collection($request, $items, $total, $page['number'], $page['size'], $included));
    }
}
