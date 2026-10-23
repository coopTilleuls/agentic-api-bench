<?php

namespace App\Catalog;

use App\Http\Pagination;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class AuthorRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public function page(Pagination $page): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
        $rows = $this->db->fetchAllAssociative(
            \sprintf(
                'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books FROM author a ORDER BY a.id LIMIT %d OFFSET %d',
                $page->limit(),
                $page->offset(),
            ),
        );

        return [$rows, $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books FROM author a WHERE a.id = ?',
            [$id],
        );

        return $row ?: null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array<string, mixed>> authors keyed by id
     */
    public function byIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name FROM author WHERE id IN (:ids)',
            ['ids' => array_values(array_unique($ids))],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return array_column($rows, null, 'id');
    }

    /**
     * Published books of the given authors, in id order, grouped by author id.
     *
     * @param list<int> $authorIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function publishedBooksByAuthor(array $authorIds): array
    {
        $grouped = [];
        if ([] === $authorIds) {
            return $grouped;
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, title, price_cents, published_at, stock, author_id FROM book WHERE published AND author_id IN (:ids) ORDER BY id',
            ['ids' => array_values(array_unique($authorIds))],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $grouped[(int) $row['author_id']][] = $row;
        }

        return $grouped;
    }
}
