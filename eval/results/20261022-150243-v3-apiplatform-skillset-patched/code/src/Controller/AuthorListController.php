<?php

namespace App\Controller;

use App\JsonApi\Input;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiResponse;
use App\JsonApi\Page;
use App\JsonApi\Resources;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthorListController
{
    private const SELECT = 'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published = TRUE) AS published_books FROM author a';

    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request): Response
    {
        Input::includes($request, []);
        if ($request->query->has('sort')) {
            throw JsonApiException::badRequest('sort', 'Unknown sort field "'.$request->query->get('sort').'".');
        }

        $page = Page::fromRequest($request);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
        $rows = $this->db->fetchAllAssociative(
            sprintf('%s ORDER BY a.id ASC LIMIT %d OFFSET %d', self::SELECT, $page->size, $page->offset()),
        );

        return JsonApiResponse::document([
            'data' => array_map(static fn (array $row): array => Resources::author($row), $rows),
            'links' => $page->links($request, $total),
            'meta' => ['totalItems' => $total],
        ]);
    }
}
