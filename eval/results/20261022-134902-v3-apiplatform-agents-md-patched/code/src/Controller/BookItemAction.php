<?php

namespace App\Controller;

use App\Bookshop\Presenter;
use App\Bookshop\Store;
use App\JsonApi\Document;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class BookItemAction
{
    public function __construct(private readonly Store $store)
    {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $bookId = QueryParams::pathId($id);
        $row = null === $bookId ? null : $this->store->findBook($bookId);
        if (null === $row || !$row['published']) {
            throw JsonApiException::create(404, 'Not found', 'No book with this id.');
        }
        $include = QueryParams::include($request, ['author']);

        $included = [];
        if (in_array('author', $include, true)) {
            foreach ($this->store->authorsByIds([(int) $row['author_id']]) as $author) {
                $included[] = Presenter::authorIncluded($request, $author);
            }
        }

        return Document::response(Document::single(Presenter::book($request, $row), $included));
    }
}
