<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class PurchaseRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function customerExists(int $customerId): bool
    {
        return (bool) $this->db->fetchOne('SELECT 1 FROM customer WHERE id = ?', [$customerId]);
    }

    public function countFor(int $customerId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pageFor(int $customerId, int $limit, int $offset): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset",
            [$customerId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFor(int $customerId, int $id): ?array
    {
        return $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$id, $customerId],
        ) ?: null;
    }

    /**
     * Lines of the given purchases, keyed by purchase id.
     *
     * @param list<int|string> $purchaseIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesFor(array $purchaseIds): array
    {
        if ([] === $purchaseIds) {
            return [];
        }

        $lines = [];
        $rows = $this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN ('.Placeholders::for($purchaseIds).') ORDER BY pl.id',
            array_values($purchaseIds),
        );
        foreach ($rows as $row) {
            $lines[(int) $row['purchase_id']][] = $row;
        }

        return $lines;
    }
}
