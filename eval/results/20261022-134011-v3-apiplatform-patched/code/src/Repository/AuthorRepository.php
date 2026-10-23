<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class AuthorRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function count(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function page(int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, name FROM author ORDER BY id LIMIT $limit OFFSET $offset",
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->db->fetchAssociative('SELECT id, name FROM author WHERE id = ?', [$id]) ?: null;
    }

    /**
     * @param list<int|string> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            'SELECT id, name FROM author WHERE id IN ('.Placeholders::for($ids).') ORDER BY id',
            array_values($ids),
        );
    }
}
