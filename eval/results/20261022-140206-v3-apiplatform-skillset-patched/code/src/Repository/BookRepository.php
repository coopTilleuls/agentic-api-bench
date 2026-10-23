<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads and updates the book table. Only published books are visible unless said otherwise.
 */
final class BookRepository
{
    /** Sort fields of the books collection, mapped to their columns. */
    public const SORT_COLUMNS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $criteria keys: title, author, minPrice, maxPrice, publishedAfter, publishedBefore, inStock
     * @param list<array{string, 'ASC'|'DESC'}> $sorts
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function findPublished(array $criteria, array $sorts, int $offset, int $limit): array
    {
        [$where, $params, $types] = $this->criteria($criteria);
        $from = 'FROM book b JOIN author a ON a.id = b.author_id'.$where;

        $order = [];
        foreach ($sorts as [$field, $direction]) {
            $order[] = self::SORT_COLUMNS[$field].' '.$direction;
        }
        $order[] = 'b.id ASC';

        $total = (int) $this->connection->fetchOne("SELECT COUNT(*) $from", $params, $types);
        $items = $this->connection->fetchAllAssociative(
            "SELECT b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock $from ORDER BY ".implode(', ', $order).' LIMIT :limit OFFSET :offset',
            $params + ['limit' => $limit, 'offset' => $offset],
            $types + ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT b.id, b.title, b.author_id, b.price_cents, b.published, b.published_at, b.stock FROM book b WHERE b.id = :id',
            ['id' => $id],
        );

        return false === $row ? null : $row;
    }

    /**
     * Published books of an author, in id order.
     *
     * @return list<array<string, mixed>>
     */
    public function findPublishedByAuthor(int $authorId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock FROM book b WHERE b.author_id = :authorId AND b.published ORDER BY b.id',
            ['authorId' => $authorId],
        );
    }

    /**
     * @param array<string, mixed> $values columns to set, among title, price_cents and stock
     */
    public function update(int $id, array $values): void
    {
        $assignments = [];
        foreach (array_keys($values) as $column) {
            $assignments[] = "$column = :$column";
        }

        $this->connection->executeStatement(
            'UPDATE book SET '.implode(', ', $assignments).' WHERE id = :id',
            $values + ['id' => $id],
        );
    }

    /**
     * Locks the given books until the end of the transaction, for a purchase.
     *
     * @param list<int> $ids
     * @return array<int, array<string, mixed>> keyed by book id
     */
    public function lockByIds(array $ids): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.id, b.price_cents, b.published, b.stock FROM book b WHERE b.id IN (:ids) ORDER BY b.id FOR UPDATE',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $books = [];
        foreach ($rows as $row) {
            $books[(int) $row['id']] = $row;
        }

        return $books;
    }

    public function decreaseStock(int $id, int $quantity): void
    {
        $this->connection->executeStatement(
            'UPDATE book SET stock = stock - :quantity WHERE id = :id',
            ['quantity' => $quantity, 'id' => $id],
            ['quantity' => ParameterType::INTEGER, 'id' => ParameterType::INTEGER],
        );
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{string, array<string, mixed>, array<string, mixed>}
     */
    private function criteria(array $criteria): array
    {
        $conditions = ['b.published'];
        $params = [];
        $types = [];

        if (null !== $criteria['title'] && '' !== $criteria['title']) {
            $conditions[] = "b.title ILIKE :title ESCAPE '\\'";
            $params['title'] = '%'.addcslashes($criteria['title'], '%_\\').'%';
        }
        if (null !== $criteria['author']) {
            $conditions[] = 'a.name = :author';
            $params['author'] = $criteria['author'];
        }
        if (null !== $criteria['minPrice']) {
            $conditions[] = 'b.price_cents >= :minPrice';
            $params['minPrice'] = $criteria['minPrice'];
            $types['minPrice'] = ParameterType::INTEGER;
        }
        if (null !== $criteria['maxPrice']) {
            $conditions[] = 'b.price_cents <= :maxPrice';
            $params['maxPrice'] = $criteria['maxPrice'];
            $types['maxPrice'] = ParameterType::INTEGER;
        }
        if (null !== $criteria['publishedAfter']) {
            $conditions[] = 'b.published_at >= :publishedAfter';
            $params['publishedAfter'] = $criteria['publishedAfter'];
        }
        if (null !== $criteria['publishedBefore']) {
            $conditions[] = 'b.published_at <= :publishedBefore';
            $params['publishedBefore'] = $criteria['publishedBefore'];
        }
        if (null !== $criteria['inStock']) {
            $conditions[] = $criteria['inStock'] ? 'b.stock > 0' : 'b.stock = 0';
        }

        return [' WHERE '.implode(' AND ', $conditions), $params, $types];
    }
}
