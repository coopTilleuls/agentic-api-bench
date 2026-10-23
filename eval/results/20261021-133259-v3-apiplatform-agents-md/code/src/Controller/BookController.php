<?php

namespace App\Controller;

use App\Bookshop\BookshopRepository;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use App\JsonApi\Resources;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class BookController
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    #[Route('/books', name: 'books_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $page = JsonApi::page($request);
        $include = JsonApi::includes($request, ['author']);
        $result = $this->repository->listBooks($this->filters($request), $this->order($request), $page);

        $document = [
            'data' => array_map([Resources::class, 'book'], $result['rows']),
            'links' => JsonApi::pageLinks($request, $page, $result['total']),
            'meta' => ['totalItems' => $result['total']],
        ];
        if (in_array('author', $include, true)) {
            $document['included'] = array_map(
                [Resources::class, 'authorRef'],
                $this->repository->authorsByIds(array_values(array_unique(array_map('intval', array_column($result['rows'], 'author_id'))))),
            );
        }

        return JsonApi::document($document);
    }

    #[Route('/books/{id}', name: 'books_show', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $include = JsonApi::includes($request, ['author']);
        $bookId = JsonApi::id($id);
        $book = null === $bookId ? null : $this->repository->findBook($bookId, publishedOnly: true);
        if (null === $book) {
            throw new NotFoundHttpException(sprintf('Book "%s" not found.', $id));
        }

        $document = ['data' => Resources::book($book)];
        if ([] !== $include) {
            $document['included'] = array_map(
                [Resources::class, 'authorRef'],
                $this->repository->authorsByIds([(int) $book['author_id']]),
            );
        }

        return JsonApi::document($document);
    }

    #[Route('/books/{id}', name: 'books_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): Response
    {
        if (!JsonApi::hasJsonApiContentType($request)) {
            throw JsonApiException::one(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }

        $bookId = JsonApi::id($id);
        $book = null === $bookId ? null : $this->repository->findBook($bookId, publishedOnly: false);
        if (null === $book) {
            throw new NotFoundHttpException(sprintf('Book "%s" not found.', $id));
        }

        $body = JsonApi::body($request);
        $data = $body['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw JsonApiException::one(422, 'Invalid document', 'The document must contain a data object.', '/data');
        }
        if (!array_key_exists('type', $data)) {
            throw JsonApiException::one(422, 'Invalid document', 'The data object must contain a type.', '/data/type');
        }
        if ('books' !== $data['type']) {
            throw JsonApiException::one(409, 'Conflict', 'The resource type must be books.', '/data/type');
        }
        if (array_key_exists('id', $data) && (!is_scalar($data['id']) || (string) $data['id'] !== (string) $bookId)) {
            throw JsonApiException::one(409, 'Conflict', sprintf('The id in the document must be %d.', $bookId), '/data/id');
        }

        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw JsonApiException::one(422, 'Invalid document', 'The attributes must be an object.', '/data/attributes');
        }

        $errors = [];
        $changes = [];
        if (array_key_exists('title', $attributes)) {
            if (!is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $errors[] = JsonApi::error(422, 'Invalid attribute', 'title must be a non-empty string.', '/data/attributes/title');
            } else {
                $changes['title'] = $attributes['title'];
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }
            if (!is_int($attributes[$attribute]) || $attributes[$attribute] < 0) {
                $errors[] = JsonApi::error(422, 'Invalid attribute', "$attribute must be an integer of at least 0.", "/data/attributes/$attribute");
            } else {
                $changes[$column] = $attributes[$attribute];
            }
        }
        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        if ([] !== $changes) {
            $this->repository->updateBook($bookId, $changes);
        }

        $updated = $this->repository->findBook($bookId, publishedOnly: false);
        if (null === $updated || !$updated['published']) {
            return new Response(null, 204);
        }

        return JsonApi::document(['data' => Resources::book($updated)]);
    }

    /**
     * @return array{title: ?string, author: ?string, minPrice: ?int, maxPrice: ?int, publishedAfter: ?string, publishedBefore: ?string, inStock: ?bool}
     */
    private function filters(Request $request): array
    {
        $raw = $request->query->all()['filter'] ?? [];
        $raw = is_array($raw) ? $raw : [];

        return [
            'title' => self::filterText($raw, 'title'),
            'author' => self::filterText($raw, 'author'),
            'minPrice' => self::filterInt($raw, 'minPrice'),
            'maxPrice' => self::filterInt($raw, 'maxPrice'),
            'publishedAfter' => self::filterDate($raw, 'publishedAfter'),
            'publishedBefore' => self::filterDate($raw, 'publishedBefore'),
            'inStock' => self::filterBool($raw, 'inStock'),
        ];
    }

    /**
     * @param array<mixed> $raw
     */
    private static function filterText(array $raw, string $name): ?string
    {
        $value = $raw[$name] ?? null;
        if (null === $value || is_string($value)) {
            return $value;
        }

        throw self::invalidFilter($name, 'must be a single value');
    }

    /**
     * @param array<mixed> $raw
     */
    private static function filterInt(array $raw, string $name): ?int
    {
        $value = self::filterText($raw, $name);
        if (null === $value) {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $value)) {
            throw self::invalidFilter($name, 'must be an integer');
        }

        return max(-2147483648, min(2147483647, (int) $value));
    }

    /**
     * @param array<mixed> $raw
     */
    private static function filterDate(array $raw, string $name): ?string
    {
        $value = self::filterText($raw, $name);
        if (null === $value) {
            return null;
        }

        return JsonApi::date($value) ?? throw self::invalidFilter($name, 'must be a date (YYYY-MM-DD)');
    }

    /**
     * @param array<mixed> $raw
     */
    private static function filterBool(array $raw, string $name): ?bool
    {
        $value = self::filterText($raw, $name);

        return match ($value) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw self::invalidFilter($name, 'must be true or false'),
        };
    }

    private static function invalidFilter(string $name, string $requirement): JsonApiException
    {
        return JsonApiException::one(400, 'Invalid query parameter', "filter[$name] $requirement.", parameter: "filter[$name]");
    }

    /**
     * @return list<string> ORDER BY terms
     */
    private function order(Request $request): array
    {
        $raw = $request->query->all()['sort'] ?? null;
        if (null === $raw) {
            return [];
        }
        if (!is_string($raw) || '' === $raw) {
            throw JsonApiException::one(400, 'Invalid query parameter', 'sort must be a comma separated list of fields.', parameter: 'sort');
        }

        $columns = [
            'priceCents' => 'b.price_cents',
            'title' => 'b.title COLLATE "C"',
            'publishedAt' => 'b.published_at',
        ];
        $order = [];
        foreach (explode(',', $raw) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!isset($columns[$field])) {
                throw JsonApiException::one(400, 'Invalid query parameter', sprintf('Unknown sort field "%s".', $field), parameter: 'sort');
            }
            $order[] = $columns[$field].($descending ? ' DESC' : ' ASC');
        }

        return $order;
    }
}
