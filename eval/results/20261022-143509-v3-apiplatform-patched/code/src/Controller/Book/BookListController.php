<?php

namespace App\Controller\Book;

use App\Catalog\AuthorRepository;
use App\Catalog\BookDocument;
use App\Catalog\BookRepository;
use App\Http\JsonApi;
use App\Http\Pagination;
use App\Http\QueryParams;
use App\Catalog\AuthorDocument;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class BookListController
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly AuthorRepository $authors,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $filters = QueryParams::filters($request);
        $include = QueryParams::include($request, ['author']);
        $order = QueryParams::sort($request, BookDocument::SORT_FIELDS);
        $page = Pagination::fromRequest($request);

        $criteria = [
            'title' => QueryParams::string($filters, 'title'),
            'author' => QueryParams::string($filters, 'author'),
            'minPrice' => QueryParams::integer($filters, 'minPrice'),
            'maxPrice' => QueryParams::integer($filters, 'maxPrice'),
            'publishedAfter' => QueryParams::date($filters, 'publishedAfter'),
            'publishedBefore' => QueryParams::date($filters, 'publishedBefore'),
            'inStock' => QueryParams::boolean($filters, 'inStock'),
        ];

        [$rows, $total] = $this->books->search($criteria, $order, $page);
        $baseUrl = JsonApi::baseUrl($request);

        $document = [
            'data' => array_map(static fn (array $row): array => BookDocument::resource($row, $baseUrl), $rows),
            'links' => $page->links($request, $total),
            'meta' => ['totalItems' => $total],
        ];
        if (\in_array('author', $include, true)) {
            $document['included'] = $this->includedAuthors($rows, $baseUrl);
        }

        return JsonApi::document($document);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function includedAuthors(array $rows, string $baseUrl): array
    {
        $authors = $this->authors->byIds(array_map(static fn (array $row): int => (int) $row['author_id'], $rows));

        return array_values(array_map(
            static fn (array $author): array => AuthorDocument::included($author, $baseUrl),
            $authors,
        ));
    }
}
