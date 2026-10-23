<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads the author table.
 */
final class AuthorRepository
{
    private const PUBLISHED_BOOKS = '(SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published) AS published_books';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function findPage(int $offset, int $limit): array
    {
        $items = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.name, '.self::PUBLISHED_BOOKS.' FROM author a ORDER BY a.id LIMIT :limit OFFSET :offset',
            ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return ['items' => $items, 'total' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM author')];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.id, a.name, '.self::PUBLISHED_BOOKS.' FROM author a WHERE a.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : $row;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> keyed by author id
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT a.id, a.name, '.self::PUBLISHED_BOOKS.' FROM author a WHERE a.id IN (:ids)',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $authors = [];
        foreach ($rows as $row) {
            $authors[(int) $row['id']] = $row;
        }

        return $authors;
    }
}
