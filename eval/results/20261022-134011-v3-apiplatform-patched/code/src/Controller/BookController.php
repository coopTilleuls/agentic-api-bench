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

final class BookController
{
    private const SORT_FIELDS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(
        private readonly BookRepository $books,
        private readonly AuthorRepository $authors,
    ) {
    }

    #[Route('/books', name: 'books_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filters = self::filters($request);
        $sort = Query::sort($request, self::SORT_FIELDS);
        [$number, $size] = Query::page($request);
        $include = Query::include($request, ['author']);

        $total = $this->books->count($filters);
        $rows = $this->books->page($filters, $sort, $size, ($number - 1) * $size);

        $document = [
            'data' => array_map(Resources::book(...), $rows),
            'links' => Query::links($request, $number, $size, $total),
            'meta' => ['totalItems' => $total],
        ];
        if ([] !== $include) {
            $document['included'] = $this->includedAuthors($rows);
        }

        return Document::respond($document);
    }

    #[Route('/books/{id}', name: 'books_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $include = Query::include($request, ['author']);
        $bookId = Query::pathId($id);
        $row = null !== $bookId ? $this->books->findPublished($bookId) : null;
        if (null === $row) {
            throw ApiException::status(404, 'Book not found.');
        }

        $document = [
            'data' => Resources::book($row),
            'links' => ['self' => "/books/$bookId"],
        ];
        if ([] !== $include) {
            $document['included'] = $this->includedAuthors([$row]);
        }

        return Document::respond($document);
    }

    #[Route('/books/{id}', name: 'books_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(string $id, Request $request): Response
    {
        Document::requireMediaType($request);
        $body = Document::decodeBody($request);
        $data = $body['data'] ?? null;
        if (!\is_array($data)) {
            throw ApiException::status(400, 'The request must contain a data object.');
        }

        $bookId = Query::pathId($id);
        if (isset($data['type']) && 'books' !== $data['type']) {
            throw ApiException::status(409, 'The type must be books.');
        }
        if (\array_key_exists('id', $data) && (!is_scalar($data['id']) || (string) $data['id'] !== (string) $bookId)) {
            throw ApiException::status(409, 'The id in the body does not match the id in the path.');
        }
        if (null === $bookId || null === $this->books->findAny($bookId)) {
            throw ApiException::status(404, 'Book not found.');
        }

        $attributes = \is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $set = [];
        $errors = [];
        if (\array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (!\is_string($title) || '' === trim($title)) {
                $errors[] = ApiException::error(422, 'The title must be a non-empty string.', ['pointer' => '/data/attributes/title']);
            } else {
                $set['title'] = $title;
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (\array_key_exists($attribute, $attributes)) {
                $value = $attributes[$attribute];
                if (!\is_int($value) || $value < 0) {
                    $errors[] = ApiException::error(422, sprintf('%s must be an integer of at least 0.', $attribute), ['pointer' => "/data/attributes/$attribute"]);
                } else {
                    $set[$column] = $value;
                }
            }
        }
        if ([] !== $errors) {
            throw new ApiException($errors);
        }

        if ([] !== $set) {
            $this->books->update($bookId, $set);
        }
        $row = $this->books->findAny($bookId);
        if (!$row['published']) {
            return new Response(null, 204);
        }

        return Document::respond([
            'data' => Resources::book($row),
            'links' => ['self' => "/books/$bookId"],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function filters(Request $request): array
    {
        return [
            'title' => Query::filterString($request, 'title'),
            'author' => Query::filterString($request, 'author'),
            'minPrice' => Query::filterInteger($request, 'minPrice'),
            'maxPrice' => Query::filterInteger($request, 'maxPrice'),
            'publishedAfter' => Query::filterDate($request, 'publishedAfter'),
            'publishedBefore' => Query::filterDate($request, 'publishedBefore'),
            'inStock' => Query::filterBoolean($request, 'inStock'),
        ];
    }

    /**
     * @param list<array<string, mixed>> $bookRows
     *
     * @return list<array<string, mixed>>
     */
    private function includedAuthors(array $bookRows): array
    {
        $authorIds = array_values(array_unique(array_map(static fn (array $row) => (int) $row['author_id'], $bookRows)));

        return array_map(Resources::authorIncluded(...), $this->authors->findByIds($authorIds));
    }
}
