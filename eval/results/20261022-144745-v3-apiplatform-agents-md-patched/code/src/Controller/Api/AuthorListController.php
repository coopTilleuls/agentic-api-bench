<?php

namespace App\Controller\Api;

use App\Bookshop\Catalog;
use App\Bookshop\Presenter;
use App\JsonApi\JsonApi;
use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorListController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = ListParameters::page($request);
        $include = ListParameters::include($request, ['books']);

        $result = $this->catalog->authors($page['number'], $page['size']);
        $books = $this->catalog->publishedBooksOf(array_map(static fn (array $row): int => (int) $row['id'], $result['rows']));

        $bookIdsByAuthor = [];
        foreach ($books as $book) {
            $bookIdsByAuthor[(int) $book['author_id']][] = (string) $book['id'];
        }

        $data = array_map(
            static fn (array $row): array => Presenter::author($row, $bookIdsByAuthor[(int) $row['id']] ?? []),
            $result['rows'],
        );

        $included = [] === $include ? null : array_map(Presenter::book(...), $books);

        return JsonApi::collection($request, $page, $result['total'], $data, $included);
    }
}
