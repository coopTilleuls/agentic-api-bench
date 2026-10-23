<?php

namespace App\Controller;

use App\JsonApi\Input;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiResponse;
use App\JsonApi\Resources;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorGetController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $includes = Input::includes($request, ['books']);
        $authorId = BookGetController::id($id);

        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published = TRUE) AS published_books FROM author a WHERE a.id = ?',
            [$authorId],
        );
        if (false === $row) {
            throw JsonApiException::notFound('Author not found.');
        }

        $books = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id FROM book b WHERE b.author_id = ? AND b.published = TRUE ORDER BY b.id ASC',
            [$authorId],
        );

        $document = ['data' => Resources::author($row, $books)];
        if (in_array('books', $includes, true)) {
            $document['included'] = array_map(Resources::book(...), $books);
        }

        return JsonApiResponse::document($document);
    }
}
