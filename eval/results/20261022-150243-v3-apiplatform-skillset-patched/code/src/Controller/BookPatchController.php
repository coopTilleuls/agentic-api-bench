<?php

namespace App\Controller;

use App\JsonApi\Input;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiResponse;
use App\JsonApi\Resources;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookPatchController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $body = Input::body($request);
        $bookId = BookGetController::id($id);

        // Unpublished books are visible to PATCH only.
        $row = $this->db->fetchAssociative(
            'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id, b.published::int AS published FROM book b WHERE b.id = ?',
            [$bookId],
        );
        if (false === $row) {
            throw JsonApiException::notFound('Book not found.');
        }

        $data = $body['data'] ?? null;
        if (!is_array($data)) {
            throw JsonApiException::validation([['/data', 'The document must contain a data object.']]);
        }
        if (($data['type'] ?? null) !== 'books') {
            throw JsonApiException::conflict('The resource type must be "books".');
        }
        if (array_key_exists('id', $data) && (!is_string($data['id']) && !is_int($data['id']) || (string) $data['id'] !== (string) $bookId)) {
            throw JsonApiException::conflict(sprintf('The data id must be "%d", the id of the book in the path.', $bookId));
        }

        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw JsonApiException::validation([['/data/attributes', 'The attributes must be an object.']]);
        }

        $changes = [];
        $violations = [];

        if (array_key_exists('title', $attributes)) {
            if (!is_string($attributes['title']) || '' === trim($attributes['title'])) {
                $violations[] = ['/data/attributes/title', 'The title must be a non-empty string.'];
            } else {
                $changes['title'] = $attributes['title'];
            }
        }

        foreach (['priceCents' => 'price_cents', 'stock' => 'stock'] as $attribute => $column) {
            if (!array_key_exists($attribute, $attributes)) {
                continue;
            }
            if (!is_int($attributes[$attribute]) || $attributes[$attribute] < 0) {
                $violations[] = ['/data/attributes/'.$attribute, sprintf('The %s must be an integer greater than or equal to 0.', $attribute)];
            } else {
                $changes[$column] = $attributes[$attribute];
            }
        }

        if ([] !== $violations) {
            throw JsonApiException::validation($violations);
        }

        if ([] !== $changes) {
            $this->db->update('book', $changes, ['id' => $bookId]);
            $row = $this->db->fetchAssociative(
                'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id, b.published::int AS published FROM book b WHERE b.id = ?',
                [$bookId],
            );
        }

        if (0 === (int) $row['published']) {
            return new Response(status: 204);
        }

        return JsonApiResponse::document(['data' => Resources::book($row)]);
    }
}
