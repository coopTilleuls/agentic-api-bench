<?php

namespace App\Controller;

use App\Bookshop\Presenter;
use App\Bookshop\Store;
use App\JsonApi\Document;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthorItemAction
{
    public function __construct(private readonly Store $store)
    {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $authorId = QueryParams::pathId($id);
        $row = null === $authorId ? null : $this->store->findAuthor($authorId);
        if (null === $row) {
            throw JsonApiException::create(404, 'Not found', 'No author with this id.');
        }
        $include = QueryParams::include($request, ['books']);

        $bookIds = $this->store->publishedBookIdsByAuthor([$authorId])[$authorId];

        $included = [];
        if (in_array('books', $include, true)) {
            foreach ($this->store->publishedBooksByIds($bookIds) as $book) {
                $included[] = Presenter::book($request, $book);
            }
        }

        return Document::response(Document::single(Presenter::author($request, $row, $bookIds), $included));
    }
}
