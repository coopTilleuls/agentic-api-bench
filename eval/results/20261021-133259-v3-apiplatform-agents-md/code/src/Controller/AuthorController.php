<?php

namespace App\Controller;

use App\Bookshop\BookshopRepository;
use App\JsonApi\JsonApi;
use App\JsonApi\Resources;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class AuthorController
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    #[Route('/authors', name: 'authors_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = JsonApi::page($request);
        $include = JsonApi::includes($request, ['books']);
        $result = $this->repository->listAuthors($page);

        $books = $this->repository->publishedBooksByAuthors(array_map('intval', array_column($result['rows'], 'id')));
        $booksByAuthor = self::groupByAuthor($books);

        $document = [
            'data' => array_map(
                static fn (array $author): array => Resources::author($author, $booksByAuthor[(int) $author['id']] ?? []),
                $result['rows'],
            ),
            'links' => JsonApi::pageLinks($request, $page, $result['total']),
            'meta' => ['totalItems' => $result['total']],
        ];
        if ([] !== $include) {
            $document['included'] = array_map([Resources::class, 'book'], $books);
        }

        return JsonApi::document($document);
    }

    #[Route('/authors/{id}', name: 'authors_show', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $include = JsonApi::includes($request, ['books']);
        $authorId = JsonApi::id($id);
        $author = null === $authorId ? null : $this->repository->findAuthor($authorId);
        if (null === $author) {
            throw new NotFoundHttpException(sprintf('Author "%s" not found.', $id));
        }

        $books = $this->repository->publishedBooksByAuthors([$authorId]);
        $document = ['data' => Resources::author($author, $books)];
        if ([] !== $include) {
            $document['included'] = array_map([Resources::class, 'book'], $books);
        }

        return JsonApi::document($document);
    }

    /**
     * @param list<array<string, mixed>> $books
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private static function groupByAuthor(array $books): array
    {
        $grouped = [];
        foreach ($books as $book) {
            $grouped[(int) $book['author_id']][] = $book;
        }

        return $grouped;
    }
}
