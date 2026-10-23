<?php

namespace App\Controller\Api;

use App\Bookshop\Catalog;
use App\Bookshop\Presenter;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookItemController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $bookId = JsonApi::pathId($id);
        $include = ListParameters::include($request, ['author']);

        $row = $this->catalog->book($bookId);
        if (null === $row || 1 !== (int) $row['published']) {
            throw JsonApiException::notFound();
        }

        $document = [
            'data' => Presenter::book($row),
            'links' => ['self' => "/books/$bookId"],
        ];

        if ([] !== $include) {
            $document['included'] = array_map(Presenter::author(...), $this->catalog->authorsByIds([(int) $row['author_id']]));
        }

        return JsonApi::response($document);
    }
}
