<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Reads customers, purchases and the sales report.
 */
final class PurchaseRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function customerExists(int $id): bool
    {
        return false !== $this->db->fetchOne('SELECT id FROM customer WHERE id = ?', [$id]);
    }

    public function countPurchases(int $customerId): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
    }

    /**
     * @param list<array{0: string, 1: string}> $orders
     *
     * @return list<array<string, mixed>>
     */
    public function findPurchases(int $customerId, array $orders, int $offset, int $limit): array
    {
        $order = [];
        foreach ($orders as [$column, $direction]) {
            $order[] = "$column $direction";
        }
        $order[] = 'p.id ASC';

        return $this->db->fetchAllAssociative(
            'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.customer_id = ?'
            .' ORDER BY '.implode(', ', $order)." LIMIT $limit OFFSET $offset",
            [$customerId],
        );
    }

    /** @return array<string, mixed>|null */
    public function findPurchase(int $customerId, int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.id = ? AND p.customer_id = ?',
            [$id, $customerId],
        );

        return false === $row ? null : $row;
    }

    /**
     * Lines of the given purchases, grouped by purchase id.
     *
     * @param list<int> $purchaseIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesOf(array $purchaseIds): array
    {
        if ([] === $purchaseIds) {
            return [];
        }
        $marks = implode(', ', array_fill(0, count($purchaseIds), '?'));
        $rows = $this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents'
            ." FROM purchase_line pl JOIN book b ON b.id = pl.book_id WHERE pl.purchase_id IN ($marks) ORDER BY pl.id",
            $purchaseIds,
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row['purchase_id']][] = $row;
        }

        return $grouped;
    }

    /**
     * Books sold in [$from, $to) with their quantities and revenue.
     *
     * @return list<array<string, mixed>>
     */
    public function salesBetween(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents'
            .' FROM purchase_line pl'
            .' JOIN purchase p ON p.id = pl.purchase_id'
            .' JOIN book b ON b.id = pl.book_id'
            .' WHERE p.created_at >= ? AND p.created_at < ?'
            .' GROUP BY b.id, b.title'
            .' ORDER BY revenue_cents DESC, b.title COLLATE "C" ASC, b.id ASC',
            [$from->format('Y-m-d H:i:sP'), $toExclusive->format('Y-m-d H:i:sP')],
        );
    }
}
