<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookShow
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $include = QueryParams::include($request, ['author']);
        $book = ctype_digit($id) ? $this->catalog->findBook((int) $id) : null;
        if (null === $book) {
            throw JsonApiException::single(404, 'Not Found', sprintf('No book with id %s.', $id));
        }

        $document = ['data' => $book];
        if ([] !== $include) {
            $document['included'] = $this->catalog->includedAuthors([$book]);
        }

        return JsonApi::response($document);
    }
}
