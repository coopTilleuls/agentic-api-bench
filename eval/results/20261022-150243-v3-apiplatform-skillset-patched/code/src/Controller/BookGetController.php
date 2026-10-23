<?php

namespace App\Controller;

use App\JsonApi\Input;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiResponse;
use App\JsonApi\Resources;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class BookGetController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $includes = Input::includes($request, ['author']);
        $bookId = self::id($id);

        $row = $this->db->fetchAssociative(
            'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id FROM book b WHERE b.id = ? AND b.published = TRUE',
            [$bookId],
        );
        if (false === $row) {
            throw JsonApiException::notFound('Book not found.');
        }

        $document = ['data' => Resources::book($row)];

        if (in_array('author', $includes, true)) {
            $author = $this->db->fetchAssociative(
                'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published = TRUE) AS published_books FROM author a WHERE a.id = ?',
                [$row['author_id']],
            );
            $document['included'] = [Resources::author($author)];
        }

        return JsonApiResponse::document($document);
    }

    /** Returns the integer id of a path segment, or answers 404 when it is malformed. */
    public static function id(string $id): int
    {
        if (!preg_match('/^[0-9]{1,18}$/', $id)) {
            throw JsonApiException::notFound();
        }

        return (int) $id;
    }
}
