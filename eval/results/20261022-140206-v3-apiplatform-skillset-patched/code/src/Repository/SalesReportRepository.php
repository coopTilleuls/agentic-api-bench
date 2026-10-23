<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;

/**
 * Books sold between two days, for the sales report.
 */
final class SalesReportRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{title: string, quantity: int|string, revenue_cents: int|string}>
     */
    public function soldBetween(\DateTimeImmutable $fromIncluded, \DateTimeImmutable $toExcluded): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT b.id AS book_id, b.title, SUM(l.quantity) AS quantity, SUM(l.quantity * l.unit_price_cents) AS revenue_cents
             FROM purchase_line l
             JOIN purchase p ON p.id = l.purchase_id
             JOIN book b ON b.id = l.book_id
             WHERE p.created_at >= :from AND p.created_at < :to
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            ['from' => $fromIncluded->format(\DateTimeInterface::ATOM), 'to' => $toExcluded->format(\DateTimeInterface::ATOM)],
        );
    }
}
