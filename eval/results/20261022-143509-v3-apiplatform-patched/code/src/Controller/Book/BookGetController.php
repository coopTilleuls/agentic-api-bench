<?php

namespace App\Controller\Book;

use App\Catalog\AuthorDocument;
use App\Catalog\AuthorRepository;
use App\Catalog\BookDocument;
use App\Catalog\BookRepository;
use App\Http\ApiException;
use App\Http\JsonApi;
use App\Http\PathId;
use App\Http\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class BookGetController
{
    public function __construct(
        private readonly BookRepository $books,
        private readonly AuthorRepository $authors,
    ) {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $include = QueryParams::include($request, ['author']);
        $bookId = PathId::parse($id);
        $row = null === $bookId ? null : $this->books->find($bookId);
        if (null === $row) {
            throw new ApiException(404, 'Book not found.');
        }

        $baseUrl = JsonApi::baseUrl($request);
        $document = ['data' => BookDocument::resource($row, $baseUrl)];
        if (\in_array('author', $include, true)) {
            $authors = $this->authors->byIds([(int) $row['author_id']]);
            $document['included'] = array_values(array_map(
                static fn (array $author): array => AuthorDocument::included($author, $baseUrl),
                $authors,
            ));
        }

        return JsonApi::document($document);
    }
}
