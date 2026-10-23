<?php

namespace App\Controller;

use App\Bookshop\Presenter;
use App\Bookshop\Store;
use App\JsonApi\Document;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthorCollectionAction
{
    public function __construct(private readonly Store $store)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $page = QueryParams::page($request);
        $include = QueryParams::include($request, ['books']);

        $total = $this->store->countAuthors();
        $rows = $this->store->authorPage($page['size'], ($page['number'] - 1) * $page['size']);
        $authorIds = array_map(static fn (array $row) => (int) $row['id'], $rows);
        $booksByAuthor = $this->store->publishedBookIdsByAuthor($authorIds);

        $items = [];
        foreach ($rows as $row) {
            $items[] = Presenter::author($request, $row, $booksByAuthor[(int) $row['id']]);
        }

        $included = [];
        if (in_array('books', $include, true)) {
            $bookIds = array_merge(...array_values($booksByAuthor));
            foreach ($this->store->publishedBooksByIds($bookIds) as $book) {
                $included[] = Presenter::book($request, $book);
            }
        }

        return Document::response(Document::collection($request, $items, $total, $page['number'], $page['size'], $included));
    }
}
