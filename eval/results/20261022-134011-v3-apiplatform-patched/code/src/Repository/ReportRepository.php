<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class ReportRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Books sold in [from, to), per book, best sellers first.
     *
     * @return list<array<string, mixed>>
     */
    public function salesBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            [$from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP')],
        );
    }
}
