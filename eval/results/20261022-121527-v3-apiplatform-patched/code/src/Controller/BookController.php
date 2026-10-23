<?php

namespace App\Controller;

use App\JsonApi\ApiException;
use App\JsonApi\JsonApi;
use App\JsonApi\Serializer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BookController
{
    private const MAX_INT = 2147483647;

    private const SELECT = 'b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock';

    private const SORT_FIELDS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title COLLATE "C"',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/books', name: 'books_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $includes = JsonApi::includes($request, ['author']);
        [$where, $params] = $this->filters($request);
        $order = JsonApi::sortOrder($request, self::SORT_FIELDS);
        $order[] = 'b.id ASC';

        $page = JsonApi::fetchPage(
            $this->db,
            $request,
            self::SELECT,
            'FROM book b JOIN author a ON a.id = b.author_id WHERE '.implode(' AND ', $where),
            $params,
            implode(', ', $order),
        );

        $data = array_map(Serializer::book(...), $page['rows']);
        $included = in_array('author', $includes, true) ? $this->authorsIncluded(array_column($page['rows'], 'author_id')) : [];

        return JsonApi::listDocument($request, $page, $data, $included);
    }

    #[Route('/books/{id}', name: 'books_show', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $includes = JsonApi::includes($request, ['author']);
        $book = $this->findPublished($id);

        $body = ['data' => Serializer::book($book)];
        if (in_array('author', $includes, true)) {
            $body['included'] = $this->authorsIncluded([$book['author_id']]);
        }

        return JsonApi::document($body);
    }

    #[Route('/books/{id}', name: 'books_update', methods: ['PATCH'])]
    public function update(string $id, Request $request): Response
    {
        JsonApi::requireMediaType($request);
        $data = JsonApi::body($request);

        $bookId = JsonApi::id($id);
        $book = null === $bookId ? false : $this->db->fetchAssociative(
            'SELECT '.self::SELECT.', b.published FROM book b WHERE b.id = :id',
            ['id' => $bookId],
        );
        if (false === $book) {
            throw ApiException::of(404, "Book $id not found.");
        }

        if (isset($data['type']) && 'books' !== $data['type']) {
            throw ApiException::of(409, 'The data type must be books.', '/data/type');
        }
        if (array_key_exists('id', $data) && (!is_scalar($data['id']) || (string) $data['id'] !== (string) $book['id'])) {
            throw ApiException::of(409, "The data id must match the id of the URL, $id.", '/data/id');
        }

        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw ApiException::of(400, 'The attributes must be an object.', '/data/attributes');
        }

        $changes = [];
        $errors = [];
        if (array_key_exists('title', $attributes)) {
            $title = $attributes['title'];
            if (is_string($title) && '' !== trim($title)) {
                $changes['title'] = $title;
            } else {
                $errors[] = JsonApi::error(422, 'The title must be a non-empty string.', '/data/attributes/title');
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }
            $value = $attributes[$attribute];
            if (is_int($value) && $value >= 0 && $value <= self::MAX_INT) {
                $changes[$column] = $value;
            } else {
                $errors[] = JsonApi::error(422, "The $attribute must be an integer between 0 and ".self::MAX_INT.'.', "/data/attributes/$attribute");
            }
        }
        if ($errors) {
            throw new ApiException(422, $errors);
        }

        if ($changes) {
            $assignments = implode(', ', array_map(static fn (string $column): string => "$column = :$column", array_keys($changes)));
            $this->db->executeStatement("UPDATE book SET $assignments WHERE id = :id", $changes + ['id' => $bookId]);
        }

        $updated = $this->db->fetchAssociative(
            'SELECT '.self::SELECT.', b.published FROM book b WHERE b.id = :id',
            ['id' => $bookId],
        );
        if (!JsonApi::isTrue($updated['published'])) {
            return new Response(null, 204);
        }

        return JsonApi::document(['data' => Serializer::book($updated)]);
    }

    /** @return array{0: list<string>, 1: array<string, mixed>} SQL conditions and their parameters */
    private function filters(Request $request): array
    {
        $where = ['b.published = true'];
        $params = [];

        if (null !== ($title = JsonApi::filter($request, 'title'))) {
            $where[] = 'b.title ILIKE :title';
            $params['title'] = '%'.strtr($title, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
        }
        if (null !== ($author = JsonApi::filter($request, 'author'))) {
            $where[] = 'a.name = :author';
            $params['author'] = $author;
        }
        if (null !== ($minPrice = JsonApi::filterInt($request, 'minPrice'))) {
            $where[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = $minPrice;
        }
        if (null !== ($maxPrice = JsonApi::filterInt($request, 'maxPrice'))) {
            $where[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = $maxPrice;
        }
        if (null !== ($after = JsonApi::filterDate($request, 'publishedAfter'))) {
            $where[] = 'b.published_at >= :publishedAfter';
            $params['publishedAfter'] = $after;
        }
        if (null !== ($before = JsonApi::filterDate($request, 'publishedBefore'))) {
            $where[] = 'b.published_at <= :publishedBefore';
            $params['publishedBefore'] = $before;
        }
        if (null !== ($inStock = JsonApi::filterBool($request, 'inStock'))) {
            $where[] = $inStock ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [$where, $params];
    }

    /** @return array<string, mixed> */
    private function findPublished(string $id): array
    {
        $bookId = JsonApi::id($id);
        $book = null === $bookId ? false : $this->db->fetchAssociative(
            'SELECT '.self::SELECT.' FROM book b WHERE b.id = :id AND b.published = true',
            ['id' => $bookId],
        );
        if (false === $book) {
            throw ApiException::of(404, "Book $id not found.");
        }

        return $book;
    }

    /**
     * @param list<int|string> $authorIds
     *
     * @return list<array<string, mixed>>
     */
    private function authorsIncluded(array $authorIds): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name FROM author WHERE id IN ('.JsonApi::inList($authorIds).') ORDER BY id',
        );

        return array_map(Serializer::authorIncluded(...), $rows);
    }
}
