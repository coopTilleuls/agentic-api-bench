<?php

namespace App\Sales;

use App\Http\Pagination;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PurchaseRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: int} the customer's purchases, most recent first
     */
    public function page(int $customerId, Pagination $page): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
        $rows = $this->db->fetchAllAssociative(
            \sprintf(
                'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
                $page->limit(),
                $page->offset(),
            ),
            [$customerId],
        );

        return [$rows, $total];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $customerId, int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$id, $customerId],
        );

        return $row ?: null;
    }

    /**
     * @param list<int> $purchaseIds
     *
     * @return array<int, list<array<string, mixed>>> lines keyed by purchase id, in line order
     */
    public function linesByPurchase(array $purchaseIds): array
    {
        $grouped = [];
        if ([] === $purchaseIds) {
            return $grouped;
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents FROM purchase_line pl JOIN book b ON b.id = pl.book_id WHERE pl.purchase_id IN (:ids) ORDER BY pl.id',
            ['ids' => $purchaseIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($rows as $row) {
            $grouped[(int) $row['purchase_id']][] = $row;
        }

        return $grouped;
    }
}
