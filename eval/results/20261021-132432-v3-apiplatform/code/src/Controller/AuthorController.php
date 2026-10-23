<?php

namespace App\Controller;

use App\Http\ApiException;
use App\JsonApi\JsonApi;
use App\JsonApi\Query;
use App\JsonApi\Resources;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/authors')]
final class AuthorController
{
    public function __construct(
        private readonly AuthorRepository $authors,
        private readonly BookRepository $books,
    ) {
    }

    #[Route('', name: 'authors_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$number, $size] = Query::page($request);
        Query::include($request, []);
        Query::sort($request, []);

        [$rows, $total] = $this->authors->paginate(Query::offset($number, $size), $size);

        return JsonApi::respond([
            'data' => array_map(fn (array $row): array => Resources::author($row, $request), $rows),
        ] + JsonApi::page($request, $number, $size, $total));
    }

    #[Route('/{id}', name: 'authors_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, string $id): JsonResponse
    {
        $include = Query::include($request, ['books']);

        $authorId = Query::id($id);
        $row = null === $authorId ? null : $this->authors->find($authorId);
        if (null === $row) {
            throw ApiException::error(404, 'The author does not exist.');
        }

        $books = $this->books->publishedByAuthor($authorId);
        $document = [
            'data' => Resources::author($row, $request, $books),
            'links' => ['self' => JsonApi::absolute($request, '/authors/'.$authorId)],
        ];
        if ([] !== $include) {
            $document['included'] = array_map(fn (array $book): array => Resources::book($book, $request), $books);
        }

        return JsonApi::respond($document);
    }
}
