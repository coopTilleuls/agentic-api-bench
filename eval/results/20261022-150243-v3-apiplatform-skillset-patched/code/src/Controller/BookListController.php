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

final class BookListController
{
    /** Sortable fields of the sort parameter, mapped to their columns. */
    private const SORTS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title COLLATE "C"',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = Page::fromRequest($request);
        $includes = Input::includes($request, ['author']);
        [$where, $params] = $this->filters($request);
        $order = $this->order($request);

        $from = 'FROM book b JOIN author a ON a.id = b.author_id WHERE '.implode(' AND ', $where);
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) $from", $params);

        $rows = $this->db->fetchAllAssociative(
            sprintf(
                'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id %s ORDER BY %s LIMIT %d OFFSET %d',
                $from,
                $order,
                $page->size,
                $page->offset(),
            ),
            $params,
        );

        $document = [
            'data' => array_map(Resources::book(...), $rows),
            'links' => $page->links($request, $total),
            'meta' => ['totalItems' => $total],
        ];

        if (in_array('author', $includes, true)) {
            $document['included'] = $this->authors($rows);
        }

        return JsonApiResponse::document($document);
    }

    /**
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function filters(Request $request): array
    {
        $where = ['b.published = TRUE'];
        $params = [];

        $filter = $request->query->all('filter');

        if (isset($filter['title'])) {
            $where[] = 'STRPOS(LOWER(b.title), LOWER(:title)) > 0';
            $params['title'] = Input::text($filter['title'], 'filter[title]');
        }
        if (isset($filter['author'])) {
            $where[] = 'a.name = :author';
            $params['author'] = Input::text($filter['author'], 'filter[author]');
        }
        if (isset($filter['minPrice'])) {
            $where[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = Input::integer($filter['minPrice'], 'filter[minPrice]');
        }
        if (isset($filter['maxPrice'])) {
            $where[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = Input::integer($filter['maxPrice'], 'filter[maxPrice]');
        }
        if (isset($filter['publishedAfter'])) {
            $where[] = 'b.published_at >= :publishedAfter';
            $params['publishedAfter'] = Input::date($filter['publishedAfter'], 'filter[publishedAfter]');
        }
        if (isset($filter['publishedBefore'])) {
            $where[] = 'b.published_at <= :publishedBefore';
            $params['publishedBefore'] = Input::date($filter['publishedBefore'], 'filter[publishedBefore]');
        }
        if (isset($filter['inStock'])) {
            $inStock = Input::text($filter['inStock'], 'filter[inStock]');
            if ('true' === $inStock) {
                $where[] = 'b.stock > 0';
            } elseif ('false' === $inStock) {
                $where[] = 'b.stock = 0';
            } else {
                throw JsonApiException::badRequest('filter[inStock]', 'filter[inStock] must be true or false.');
            }
        }

        return [$where, $params];
    }

    private function order(Request $request): string
    {
        $raw = $request->query->get('sort');
        if (null === $raw || '' === $raw) {
            return 'b.id ASC';
        }

        $terms = [];
        foreach (explode(',', Input::text($raw, 'sort')) as $term) {
            $descending = str_starts_with($term, '-');
            $field = $descending ? substr($term, 1) : $term;
            if (!isset(self::SORTS[$field])) {
                throw JsonApiException::badRequest('sort', sprintf('Unknown sort field "%s".', $field));
            }
            $terms[] = self::SORTS[$field].($descending ? ' DESC' : ' ASC');
        }
        $terms[] = 'b.id ASC';

        return implode(', ', $terms);
    }

    /**
     * The distinct authors of the given book rows, in order of appearance, as included resources.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function authors(array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['author_id'], $rows)));
        if ([] === $ids) {
            return [];
        }

        $authors = $this->db->fetchAllAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published = TRUE) AS published_books FROM author a WHERE a.id IN ('.implode(',', $ids).') ORDER BY a.id',
        );

        return array_map(Resources::author(...), $authors);
    }
}
