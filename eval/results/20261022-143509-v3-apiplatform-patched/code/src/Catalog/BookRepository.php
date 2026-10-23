<?php

namespace App\Catalog;

use App\Http\Pagination;
use Doctrine\DBAL\Connection;

final class BookRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Published books matching the criteria, one page of them and the total number of matches.
     *
     * @param array{title?: ?string, author?: ?string, minPrice?: ?int, maxPrice?: ?int, publishedAfter?: ?string, publishedBefore?: ?string, inStock?: ?bool} $criteria
     * @param list<string>                                                                                                                                       $order  ORDER BY terms, without the id tie-breaker
     *
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function search(array $criteria, array $order, Pagination $page): array
    {
        [$where, $params] = $this->conditions($criteria);
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id WHERE $whereSql",
            $params,
        );

        $order[] = 'b.id ASC';
        $rows = $this->db->fetchAllAssociative(
            \sprintf(
                'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id FROM book b JOIN author a ON a.id = b.author_id WHERE %s ORDER BY %s LIMIT %d OFFSET %d',
                $whereSql,
                implode(', ', $order),
                $page->limit(),
                $page->offset(),
            ),
            $params,
        );

        return [$rows, $total];
    }

    /**
     * @param bool $publishedOnly unpublished books are invisible except to PATCH
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, bool $publishedOnly = true): ?array
    {
        $sql = 'SELECT id, title, price_cents, published_at, stock, author_id, published FROM book WHERE id = ?';
        if ($publishedOnly) {
            $sql .= ' AND published';
        }

        return $this->db->fetchAssociative($sql, [$id]) ?: null;
    }

    /**
     * @param array<string, int|string> $changes column => new value
     */
    public function update(int $id, array $changes): void
    {
        $this->db->update('book', $changes, ['id' => $id]);
    }

    /**
     * @return array{0: list<string>, 1: array<string, mixed>}
     */
    private function conditions(array $criteria): array
    {
        $where = ['b.published'];
        $params = [];

        if (($criteria['title'] ?? null) !== null && '' !== $criteria['title']) {
            $where[] = 'b.title ILIKE :title';
            $params['title'] = '%'.addcslashes($criteria['title'], '%_\\').'%';
        }
        if (($criteria['author'] ?? null) !== null) {
            $where[] = 'a.name = :author';
            $params['author'] = $criteria['author'];
        }
        if (($criteria['minPrice'] ?? null) !== null) {
            $where[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = $criteria['minPrice'];
        }
        if (($criteria['maxPrice'] ?? null) !== null) {
            $where[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = $criteria['maxPrice'];
        }
        if (($criteria['publishedAfter'] ?? null) !== null) {
            $where[] = 'b.published_at >= :publishedAfter';
            $params['publishedAfter'] = $criteria['publishedAfter'];
        }
        if (($criteria['publishedBefore'] ?? null) !== null) {
            $where[] = 'b.published_at <= :publishedBefore';
            $params['publishedBefore'] = $criteria['publishedBefore'];
        }
        if (($criteria['inStock'] ?? null) !== null) {
            $where[] = $criteria['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [$where, $params];
    }
}
