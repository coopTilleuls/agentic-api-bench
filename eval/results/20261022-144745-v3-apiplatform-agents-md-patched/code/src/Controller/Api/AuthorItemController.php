<?php

namespace App\Controller\Api;

use App\Bookshop\Catalog;
use App\Bookshop\Presenter;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorItemController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $authorId = JsonApi::pathId($id);
        $include = ListParameters::include($request, ['books']);

        $row = $this->catalog->author($authorId) ?? throw JsonApiException::notFound();
        $books = $this->catalog->publishedBooksOf([$authorId]);

        $document = [
            'data' => Presenter::author($row, array_map(static fn (array $book): string => (string) $book['id'], $books)),
            'links' => ['self' => "/authors/$authorId"],
        ];

        if ([] !== $include) {
            $document['included'] = array_map(Presenter::book(...), $books);
        }

        return JsonApi::response($document);
    }
}
