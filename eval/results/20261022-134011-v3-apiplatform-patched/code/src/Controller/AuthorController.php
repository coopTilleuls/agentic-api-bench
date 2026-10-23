<?php

namespace App\Controller;

use App\JsonApi\ApiException;
use App\JsonApi\Document;
use App\JsonApi\Query;
use App\JsonApi\Resources;
use App\Repository\AuthorRepository;
use App\Repository\BookRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthorController
{
    public function __construct(
        private readonly AuthorRepository $authors,
        private readonly BookRepository $books,
    ) {
    }

    #[Route('/authors', name: 'authors_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        [$number, $size] = Query::page($request);
        $include = Query::include($request, ['books']);
        Query::sort($request, []);

        $total = $this->authors->count();
        $rows = $this->authors->page($size, ($number - 1) * $size);
        $bookIds = $this->books->publishedIdsByAuthor(array_column($rows, 'id'));

        $document = [
            'data' => array_map(
                fn (array $row) => Resources::author($row, $bookIds[(int) $row['id']] ?? []),
                $rows,
            ),
            'links' => Query::links($request, $number, $size, $total),
            'meta' => ['totalItems' => $total],
        ];
        if ([] !== $include) {
            $document['included'] = $this->includedBooks(array_merge(...array_values($bookIds ?: [[]])));
        }

        return Document::respond($document);
    }

    #[Route('/authors/{id}', name: 'authors_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $include = Query::include($request, ['books']);
        $authorId = Query::pathId($id);
        $row = null !== $authorId ? $this->authors->find($authorId) : null;
        if (null === $row) {
            throw ApiException::status(404, 'Author not found.');
        }

        $bookIds = $this->books->publishedIdsByAuthor([$authorId])[$authorId] ?? [];
        $document = [
            'data' => Resources::author($row, $bookIds),
            'links' => ['self' => "/authors/$authorId"],
        ];
        if ([] !== $include) {
            $document['included'] = $this->includedBooks($bookIds);
        }

        return Document::respond($document);
    }

    /**
     * @param list<int|string> $bookIds
     *
     * @return list<array<string, mixed>>
     */
    private function includedBooks(array $bookIds): array
    {
        $included = [];
        foreach ($this->books->publishedByIds($bookIds) as $row) {
            $included[] = Resources::book($row);
        }

        return $included;
    }
}
