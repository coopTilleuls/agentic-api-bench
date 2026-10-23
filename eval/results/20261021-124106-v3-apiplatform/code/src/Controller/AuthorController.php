<?php

namespace App\Controller;

use App\Http\ApiException;
use App\Http\JsonApi;
use App\Http\Query;
use App\Http\Resources;
use App\Repository\BookRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthorController
{
    public function __construct(private readonly BookRepository $books)
    {
    }

    #[Route('/authors', name: 'authors_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $include = Query::include($request, ['books']);
        [$number, $size] = Query::page($request);

        $total = $this->books->countAuthors();
        $rows = $this->books->findAuthors(($number - 1) * $size, $size);

        $books = [];
        if ([] !== $include) {
            $books = $this->books->publishedBooksOf(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        }
        $booksByAuthor = [];
        foreach ($books as $book) {
            $booksByAuthor[(int) $book['author_id']][] = ['type' => 'books', 'id' => (string) $book['id']];
        }

        $data = [];
        foreach ($rows as $row) {
            $data[] = Resources::author($row, [] === $include ? null : ($booksByAuthor[(int) $row['id']] ?? []));
        }
        $included = array_map([Resources::class, 'book'], $books);

        return JsonApi::respond(JsonApi::collection($request, $data, $total, $number, $size, $included));
    }

    #[Route('/authors/{id}', name: 'authors_show', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $include = Query::include($request, ['books']);

        $authorId = Query::id($id);
        $row = null === $authorId ? null : $this->books->findAuthor($authorId);
        if (null === $row) {
            throw ApiException::one(404, 'Author not found.');
        }

        $books = $this->books->publishedBooksOf([$authorId]);
        $linkage = array_map(static fn (array $book): array => ['type' => 'books', 'id' => (string) $book['id']], $books);

        $document = ['data' => Resources::author($row, $linkage)];
        if ([] !== $include) {
            $document['included'] = array_map([Resources::class, 'book'], $books);
        }

        return JsonApi::respond($document);
    }
}
