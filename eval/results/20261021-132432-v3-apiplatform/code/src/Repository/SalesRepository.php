<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

final class SalesRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * The books sold by purchases made between two days (UTC, both included), best sellers first.
     *
     * @return list<array{title: string, quantity: string, revenue: string}>
     */
    public function soldBetween(string $from, string $to): array
    {
        $end = (new \DateTimeImmutable($to, new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

        return $this->db->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= ?::timestamptz AND p.created_at < ?::timestamptz
             GROUP BY b.id, b.title
             ORDER BY revenue DESC, b.title ASC, b.id ASC',
            [$from.' 00:00:00+00', $end.' 00:00:00+00'],
        );
    }
}
