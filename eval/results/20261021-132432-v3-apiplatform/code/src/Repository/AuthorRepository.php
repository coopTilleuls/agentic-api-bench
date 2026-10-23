<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class AuthorRepository
{
    private const SELECT = 'SELECT a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published) AS published_books FROM author a';

    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} */
    public function paginate(int $offset, int $size): array
    {
        $rows = $this->db->fetchAllAssociative(self::SELECT.' ORDER BY a.id LIMIT '.$size.' OFFSET '.$offset);
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');

        return [$rows, $total];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->db->fetchAssociative(self::SELECT.' WHERE a.id = ?', [$id]);

        return false === $row ? null : $row;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function findByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->db->fetchAllAssociative(
            self::SELECT.' WHERE a.id IN (?) ORDER BY a.id',
            [$ids],
            [ArrayParameterType::INTEGER],
        );
    }
}
