<?php

namespace App\Controller\Author;

use App\Catalog\AuthorDocument;
use App\Catalog\AuthorRepository;
use App\Catalog\BookDocument;
use App\Http\ApiException;
use App\Http\JsonApi;
use App\Http\PathId;
use App\Http\QueryParams;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AuthorGetController
{
    public function __construct(private readonly AuthorRepository $authors)
    {
    }

    public function __invoke(Request $request, string $id): JsonResponse
    {
        $include = QueryParams::include($request, ['books']);
        $authorId = PathId::parse($id);
        $row = null === $authorId ? null : $this->authors->find($authorId);
        if (null === $row) {
            throw new ApiException(404, 'Author not found.');
        }

        $baseUrl = JsonApi::baseUrl($request);
        $books = $this->authors->publishedBooksByAuthor([$authorId])[$authorId] ?? [];

        $document = ['data' => AuthorDocument::resource($row, $baseUrl, $books)];
        if (\in_array('books', $include, true)) {
            $document['included'] = array_map(
                static fn (array $book): array => BookDocument::resource($book, $baseUrl),
                $books,
            );
        }

        return JsonApi::document($document);
    }
}
