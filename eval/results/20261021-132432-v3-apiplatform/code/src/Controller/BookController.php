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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/books')]
final class BookController
{
    private const SORT = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(
        private readonly BookRepository $books,
        private readonly AuthorRepository $authors,
    ) {
    }

    #[Route('', name: 'books_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        [$number, $size] = Query::page($request);
        $include = Query::include($request, ['author']);
        $sort = Query::sort($request, self::SORT);
        $filters = array_filter([
            'title' => Query::filter($request, 'title'),
            'author' => Query::filter($request, 'author'),
            'minPrice' => Query::filterInt($request, 'minPrice'),
            'maxPrice' => Query::filterInt($request, 'maxPrice'),
            'publishedAfter' => Query::filterDate($request, 'publishedAfter'),
            'publishedBefore' => Query::filterDate($request, 'publishedBefore'),
            'inStock' => Query::filterBool($request, 'inStock'),
        ], static fn (mixed $value): bool => null !== $value);

        [$rows, $total] = $this->books->paginate($filters, $sort, Query::offset($number, $size), $size);

        $document = [
            'data' => array_map(fn (array $row): array => Resources::book($row, $request), $rows),
        ] + JsonApi::page($request, $number, $size, $total);
        if ([] !== $include) {
            $document['included'] = $this->authorsOf($rows, $request);
        }

        return JsonApi::respond($document);
    }

    #[Route('/{id}', name: 'books_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Request $request, string $id): JsonResponse
    {
        $include = Query::include($request, ['author']);
        $row = $this->findPublished($id);

        $document = [
            'data' => Resources::book($row, $request),
            'links' => ['self' => JsonApi::absolute($request, '/books/'.$row['id'])],
        ];
        if ([] !== $include) {
            $document['included'] = $this->authorsOf([$row], $request);
        }

        return JsonApi::respond($document);
    }

    #[Route('/{id}', name: 'books_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(Request $request, string $id): Response
    {
        $document = JsonApi::requestDocument($request);

        $bookId = Query::id($id);
        $book = null === $bookId ? null : $this->books->find($bookId);
        if (null === $book) {
            throw ApiException::error(404, 'The book does not exist.');
        }

        $data = $document['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw ApiException::error(422, 'The request must have a data object.', pointer: '/data');
        }
        if (isset($data['type']) && 'books' !== $data['type']) {
            throw ApiException::error(409, 'The data type must be books.');
        }
        if (isset($data['id']) && (!is_scalar($data['id']) || (string) $data['id'] !== (string) $bookId)) {
            throw ApiException::error(409, 'The data id must be '.$bookId.', the id of the book in the path.');
        }

        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes) || (array_is_list($attributes) && [] !== $attributes)) {
            throw ApiException::error(422, 'The attributes must be an object.', pointer: '/data/attributes');
        }

        $columns = [];
        $errors = [];
        if (array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (!is_string($title) || '' === trim($title)) {
                $errors[] = self::invalid('/data/attributes/title', 'title must be a non-empty string.');
            } else {
                $columns['title'] = $title;
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $attributes[$attribute];
            if (!is_int($value) || $value < 0 || $value > Query::MAX_INT) {
                $errors[] = self::invalid('/data/attributes/'.$attribute, $attribute.' must be an integer from 0.');
            } else {
                $columns[$column] = $value;
            }
        }
        if ([] !== $errors) {
            throw new ApiException(422, $errors);
        }

        if ([] !== $columns) {
            $this->books->update($bookId, $columns);
            $book = $this->books->find($bookId);
        }

        if (!$book['published']) {
            return new Response(null, 204);
        }

        return JsonApi::respond([
            'data' => Resources::book($book, $request),
            'links' => ['self' => JsonApi::absolute($request, '/books/'.$bookId)],
        ]);
    }

    /** @return array<string, mixed> */
    private function findPublished(string $id): array
    {
        $bookId = Query::id($id);
        $row = null === $bookId ? null : $this->books->findPublished($bookId);
        if (null === $row) {
            throw ApiException::error(404, 'The book does not exist.');
        }

        return $row;
    }

    /**
     * The authors of the given books, as included resources.
     *
     * @param list<array<string, mixed>> $books
     *
     * @return list<array<string, mixed>>
     */
    private function authorsOf(array $books, Request $request): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $book): int => (int) $book['author_id'], $books)));

        return array_map(
            fn (array $row): array => Resources::author($row, $request),
            $this->authors->findByIds($ids),
        );
    }

    /** @return array<string, mixed> */
    private static function invalid(string $pointer, string $detail): array
    {
        return ['status' => '422', 'title' => Response::$statusTexts[422], 'detail' => $detail, 'source' => ['pointer' => $pointer]];
    }
}
