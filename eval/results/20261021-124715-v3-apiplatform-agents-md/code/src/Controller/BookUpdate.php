<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookUpdate
{
    public function __construct(
        private readonly Connection $db,
        private readonly Catalog $catalog,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        if (!JsonApi::isJsonApiContentType($request->headers->get('Content-Type'))) {
            throw JsonApiException::single(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }

        $data = JsonApi::requestData($request->getContent(), 'books');
        if ((string) ($data['id'] ?? '') !== $id) {
            throw JsonApiException::single(409, 'Conflict', 'The data id must match the id of the URL.', '/data/id');
        }

        $book = ctype_digit($id) ? $this->db->fetchAssociative('SELECT id, published FROM book WHERE id = :id', ['id' => (int) $id]) : false;
        if (false === $book) {
            throw JsonApiException::single(404, 'Not Found', sprintf('No book with id %s.', $id));
        }

        $attributes = $data['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw JsonApiException::single(422, 'Unprocessable Entity', 'The attributes must be an object.', '/data/attributes');
        }

        $changes = [];
        $errors = [];
        if (\array_key_exists('title', $attributes)) {
            if (!\is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $errors[] = self::invalid('/data/attributes/title', 'The title must be a non-empty string.');
            } else {
                $changes['title'] = $attributes['title'];
            }
        }
        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $name => $column) {
            if (\array_key_exists($name, $attributes)) {
                if (!\is_int($attributes[$name]) || $attributes[$name] < 0) {
                    $errors[] = self::invalid('/data/attributes/' . $name, sprintf('%s must be an integer of at least 0.', $name));
                } else {
                    $changes[$column] = $attributes[$name];
                }
            }
        }
        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        if ([] !== $changes) {
            $assignments = implode(', ', array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($changes)));
            $this->db->executeStatement(
                'UPDATE book SET ' . $assignments . ' WHERE id = :id',
                $changes + ['id' => (int) $id],
            );
        }

        if (!(bool) $book['published']) {
            return new Response(null, 204);
        }

        return JsonApi::response(['data' => $this->catalog->findBook((int) $id)]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function invalid(string $pointer, string $detail): array
    {
        return ['status' => '422', 'title' => 'Unprocessable Entity', 'detail' => $detail, 'source' => ['pointer' => $pointer]];
    }
}
