<?php

namespace App\Bookshop;

use App\JsonApi\QueryParams;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the published books and the authors, and presents them as JSON:API resource objects.
 */
final class Catalog
{
    /** Sortable book fields, API name => column. */
    private const BOOK_SORTS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title COLLATE "C"',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, included: list<array<string, mixed>>}
     */
    public function listBooks(Request $request, int $offset, int $size): array
    {
        $sorts = QueryParams::sort($request, array_keys(self::BOOK_SORTS));
        $include = QueryParams::include($request, ['author']);
        $filters = QueryParams::filters($request);

        $where = ['b.published = true'];
        $params = [];

        if (isset($filters['title'])) {
            $where[] = 'b.title ILIKE :title';
            $params['title'] = '%' . addcslashes($filters['title'], '%_\\') . '%';
        }
        if (isset($filters['author'])) {
            $where[] = 'a.name = :author';
            $params['author'] = $filters['author'];
        }
        if (null !== ($minPrice = QueryParams::filterInt($filters, 'minPrice'))) {
            $where[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = $minPrice;
        }
        if (null !== ($maxPrice = QueryParams::filterInt($filters, 'maxPrice'))) {
            $where[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = $maxPrice;
        }
        if (null !== ($after = QueryParams::filterDate($filters, 'publishedAfter'))) {
            $where[] = 'b.published_at >= :publishedAfter';
            $params['publishedAfter'] = $after;
        }
        if (null !== ($before = QueryParams::filterDate($filters, 'publishedBefore'))) {
            $where[] = 'b.published_at <= :publishedBefore';
            $params['publishedBefore'] = $before;
        }
        if (null !== ($inStock = QueryParams::filterBool($filters, 'inStock'))) {
            $where[] = $inStock ? 'b.stock > 0' : 'b.stock = 0';
        }

        $from = 'FROM book b JOIN author a ON a.id = b.author_id WHERE ' . implode(' AND ', $where);

        $order = [];
        foreach ($sorts as $sort) {
            $order[] = self::BOOK_SORTS[$sort['field']] . ($sort['descending'] ? ' DESC' : ' ASC') . ' NULLS LAST';
        }
        $order[] = 'b.id ASC';

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) ' . $from, $params);
        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock ' . $from
            . ' ORDER BY ' . implode(', ', $order) . sprintf(' LIMIT %d OFFSET %d', $size, $offset),
            $params,
        );

        $data = array_map(fn (array $row): array => $this->bookResource($row), $rows);

        return [
            'data' => $data,
            'total' => $total,
            'included' => in_array('author', $include, true) ? $this->includedAuthors($data) : [],
        ];
    }

    /**
     * @return array<string, mixed>|null the book resource object, null when unknown or unpublished
     */
    public function findBook(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, title, author_id, price_cents, published_at, stock FROM book WHERE id = :id AND published = true',
            ['id' => $id],
        );

        return false === $row ? null : $this->bookResource($row);
    }

    /**
     * The authors of book resource objects, each once, as included resource objects.
     *
     * @param list<array<string, mixed>> $books
     * @return list<array<string, mixed>>
     */
    public function includedAuthors(array $books): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (array $book): int => (int) $book['relationships']['author']['data']['id'],
            $books,
        )));

        return array_map(fn (array $row): array => $this->authorIncluded($row), $this->authorsByIds($ids));
    }

    /**
     * @return array{data: list<array<string, mixed>>, total: int, included: list<array<string, mixed>>}
     */
    public function listAuthors(Request $request, int $offset, int $size): array
    {
        QueryParams::sort($request, []);
        $include = QueryParams::include($request, ['books']);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name FROM author ORDER BY id ASC' . sprintf(' LIMIT %d OFFSET %d', $size, $offset),
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $published = $this->publishedBooksByAuthor($ids);

        $included = [];
        if (in_array('books', $include, true)) {
            foreach ($published as $books) {
                foreach ($books as $book) {
                    $included[] = $this->bookResource($book);
                }
            }
        }

        return [
            'data' => array_map(
                fn (array $row): array => $this->authorResource($row, $published[(int) $row['id']] ?? []),
                $rows,
            ),
            'total' => $total,
            'included' => $included,
        ];
    }

    /**
     * @return array<string, mixed>|null the author resource object with its books, null when unknown
     */
    public function findAuthor(int $id, Request $request): ?array
    {
        $include = QueryParams::include($request, ['books']);

        $row = $this->db->fetchAssociative('SELECT id, name FROM author WHERE id = :id', ['id' => $id]);
        if (false === $row) {
            return null;
        }

        $books = $this->publishedBooksByAuthor([$id])[$id] ?? [];

        return [
            'resource' => $this->authorResource($row, $books),
            'included' => in_array('books', $include, true) ? array_map(fn (array $book): array => $this->bookResource($book), $books) : [],
        ];
    }

    /**
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>> published book rows by author id, in id order
     */
    private function publishedBooksByAuthor(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, title, author_id, price_cents, published_at, stock FROM book
             WHERE published = true AND author_id IN (:ids) ORDER BY id ASC',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $byAuthor = [];
        foreach ($rows as $row) {
            $byAuthor[(int) $row['author_id']][] = $row;
        }

        return $byAuthor;
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>> author rows
     */
    private function authorsByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            'SELECT id, name FROM author WHERE id IN (:ids) ORDER BY id ASC',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }

    /**
     * An author included through a book: its name only.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function authorIncluded(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => ['name' => $row['name']],
            'links' => ['self' => '/authors/' . $id],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $books the published books of the author
     * @return array<string, mixed>
     */
    private function authorResource(array $row, array $books): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => [
                'name' => $row['name'],
                'publishedBooks' => \count($books),
            ],
            'relationships' => [
                'books' => [
                    'data' => array_map(
                        static fn (array $book): array => ['type' => 'books', 'id' => (string) $book['id']],
                        $books,
                    ),
                ],
            ],
            'links' => ['self' => '/authors/' . $id],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function bookResource(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'books',
            'id' => $id,
            'attributes' => [
                'title' => $row['title'],
                'priceCents' => (int) $row['price_cents'],
                'publishedAt' => $row['published_at'],
                'stock' => (int) $row['stock'],
            ],
            'relationships' => [
                'author' => ['data' => ['type' => 'authors', 'id' => (string) $row['author_id']]],
            ],
            'links' => ['self' => '/books/' . $id],
        ];
    }
}
