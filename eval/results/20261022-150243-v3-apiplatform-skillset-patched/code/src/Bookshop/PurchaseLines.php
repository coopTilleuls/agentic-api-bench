<?php

namespace App\Bookshop;

use Doctrine\DBAL\Connection;

/**
 * Loads the lines of purchases, grouped by purchase id.
 */
final class PurchaseLines
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<int|string> $purchaseIds
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function grouped(array $purchaseIds): array
    {
        if ([] === $purchaseIds) {
            return [];
        }

        $ids = implode(',', array_map('intval', $purchaseIds));
        $rows = $this->db->fetchAllAssociative(
            "SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents FROM purchase_line pl JOIN book b ON b.id = pl.book_id WHERE pl.purchase_id IN ($ids) ORDER BY pl.id ASC",
        );

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row['purchase_id']][] = $row;
        }

        return $grouped;
    }
}
