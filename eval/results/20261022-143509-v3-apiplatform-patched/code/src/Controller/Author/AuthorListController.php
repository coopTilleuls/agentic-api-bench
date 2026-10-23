<?php

namespace App\Controller\Author;

use App\Catalog\AuthorDocument;
use App\Catalog\AuthorRepository;
use App\Catalog\BookDocument;
use App\Http\JsonApi;
use App\Http\Pagination;
use App\Http\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthorListController
{
    public function __construct(private readonly AuthorRepository $authors)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $include = QueryParams::include($request, ['books']);
        $page = Pagination::fromRequest($request);

        [$rows, $total] = $this->authors->page($page);
        $baseUrl = JsonApi::baseUrl($request);

        $books = \in_array('books', $include, true)
            ? $this->authors->publishedBooksByAuthor(array_map(static fn (array $row): int => (int) $row['id'], $rows))
            : null;

        $document = [
            'data' => array_map(
                static fn (array $row): array => AuthorDocument::resource(
                    $row,
                    $baseUrl,
                    null === $books ? null : ($books[(int) $row['id']] ?? []),
                ),
                $rows,
            ),
            'links' => $page->links($request, $total),
            'meta' => ['totalItems' => $total],
        ];
        if (null !== $books) {
            $document['included'] = array_values(array_map(
                static fn (array $book): array => BookDocument::resource($book, $baseUrl),
                array_merge([], ...array_values($books)),
            ));
        }

        return JsonApi::document($document);
    }
}
