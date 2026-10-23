<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorShow
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $found = ctype_digit($id) ? $this->catalog->findAuthor((int) $id, $request) : null;
        if (null === $found) {
            throw JsonApiException::single(404, 'Not Found', sprintf('No author with id %s.', $id));
        }

        $document = ['data' => $found['resource']];
        if ($request->query->has('include')) {
            $document['included'] = $found['included'];
        }

        return JsonApi::response($document);
    }
}
