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

final class BookController
{
    private const SORT = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    private const MAX_INT = 2147483647;

    public function __construct(private readonly BookRepository $books)
    {
    }

    #[Route('/books', name: 'books_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $filters = $this->filters($request);
        $orders = Query::sort($request, self::SORT);
        $include = Query::include($request, ['author']);
        [$number, $size] = Query::page($request);

        $total = $this->books->countBooks($filters);
        $rows = $this->books->findBooks($filters, $orders, ($number - 1) * $size, $size);

        $included = [];
        if ([] !== $include) {
            $authors = $this->books->authorsByIds(array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['author_id'],
                $rows,
            ))));
            $included = array_map([Resources::class, 'authorReference'], $authors);
        }

        return JsonApi::respond(JsonApi::collection(
            $request,
            array_map([Resources::class, 'book'], $rows),
            $total,
            $number,
            $size,
            $included,
        ));
    }

    #[Route('/books/{id}', name: 'books_show', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $include = Query::include($request, ['author']);
        $row = $this->visibleBook($id);

        $document = ['data' => Resources::book($row)];
        if ([] !== $include) {
            $authors = $this->books->authorsByIds([(int) $row['author_id']]);
            $document['included'] = array_map([Resources::class, 'authorReference'], $authors);
        }

        return JsonApi::respond($document);
    }

    #[Route('/books/{id}', name: 'books_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): Response
    {
        $data = JsonApi::requestData($request);

        $bookId = Query::id($id);
        $row = null === $bookId ? null : $this->books->findBook($bookId);
        if (null === $row) {
            throw ApiException::one(404, 'Book not found.');
        }

        if ('books' !== ($data['type'] ?? null)) {
            throw ApiException::one(409, 'The resource type must be books.', '/data/type');
        }
        if (array_key_exists('id', $data) && (string) $data['id'] !== (string) $bookId) {
            throw ApiException::one(409, 'The id in the body does not match the id in the path.', '/data/id');
        }

        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw ApiException::one(400, 'attributes must be an object.', '/data/attributes');
        }

        $changes = $this->changes($attributes);
        $this->books->updateBook($bookId, $changes);

        if (!$row['published']) {
            return new Response(status: 204);
        }

        return JsonApi::respond(['data' => Resources::book($this->books->findBook($bookId))]);
    }

    /**
     * Validates the writable attributes of a PATCH and returns the column changes.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, int|string>
     *
     * @throws ApiException 422 with one error per invalid attribute
     */
    private function changes(array $attributes): array
    {
        $changes = [];
        $errors = [];

        if (array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (!is_string($title) || '' === trim($title)) {
                $errors[] = JsonApi::error(422, 'title must be a non-empty string.', '/data/attributes/title');
            } else {
                $changes['title'] = $title;
            }
        }

        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $attributes[$attribute];
            if (!is_int($value) || $value < 0 || $value > self::MAX_INT) {
                $errors[] = JsonApi::error(422, "$attribute must be an integer from 0.", "/data/attributes/$attribute");
            } else {
                $changes[$column] = $value;
            }
        }

        if ([] !== $errors) {
            throw new ApiException(422, $errors);
        }

        return $changes;
    }

    /** @return array<string, mixed> a published book, or 404 */
    private function visibleBook(string $id): array
    {
        $bookId = Query::id($id);
        $row = null === $bookId ? null : $this->books->findBook($bookId);
        if (null === $row || !$row['published']) {
            throw ApiException::one(404, 'Book not found.');
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $family = Query::family($request, 'filter');
        $filters = [];

        if (null !== ($value = Query::string($family, 'filter', 'title'))) {
            $filters['title'] = $value;
        }
        if (null !== ($value = Query::string($family, 'filter', 'author'))) {
            $filters['author'] = $value;
        }
        if (null !== ($value = Query::string($family, 'filter', 'minPrice'))) {
            $filters['minPrice'] = Query::integer($value, 'filter[minPrice]');
        }
        if (null !== ($value = Query::string($family, 'filter', 'maxPrice'))) {
            $filters['maxPrice'] = Query::integer($value, 'filter[maxPrice]');
        }
        if (null !== ($value = Query::string($family, 'filter', 'publishedAfter'))) {
            $filters['publishedAfter'] = Query::date($value, 'filter[publishedAfter]');
        }
        if (null !== ($value = Query::string($family, 'filter', 'publishedBefore'))) {
            $filters['publishedBefore'] = Query::date($value, 'filter[publishedBefore]');
        }
        if (null !== ($value = Query::string($family, 'filter', 'inStock'))) {
            $filters['inStock'] = Query::boolean($value, 'filter[inStock]');
        }

        return $filters;
    }
}
