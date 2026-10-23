<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PurchaseRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array{0: list<array<string, mixed>>, 1: int} the purchases of the page and their total */
    public function paginateFor(int $customerId, int $offset, int $size): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT '.$size.' OFFSET '.$offset,
            [$customerId],
        );
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);

        return [$rows, $total];
    }

    /** @return array<string, mixed>|null the purchase, only if it belongs to the customer */
    public function findFor(int $customerId, int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$id, $customerId],
        );

        return false === $row ? null : $row;
    }

    /**
     * The lines of the purchases, grouped by purchase id, in the order they were bought.
     *
     * @param list<int> $purchaseIds
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function linesFor(array $purchaseIds): array
    {
        $grouped = [];
        if ([] === $purchaseIds) {
            return $grouped;
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents FROM purchase_line pl JOIN book b ON b.id = pl.book_id WHERE pl.purchase_id IN (?) ORDER BY pl.id',
            [$purchaseIds],
            [ArrayParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $grouped[(int) $row['purchase_id']][] = $row;
        }

        return $grouped;
    }
}
