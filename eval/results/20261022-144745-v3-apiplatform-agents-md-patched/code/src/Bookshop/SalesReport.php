<?php

namespace App\Bookshop;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The CSV report of the books sold between two days (UTC, bounds included).
 */
final class SalesReport
{
    private const HEADER = ['title', 'quantity', 'revenueCents'];

    public function __construct(private readonly Connection $db)
    {
    }

    public function csv(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(0, 0)->modify('+1 day');

        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY b.id, b.title',
            [$start->format(\DATE_ATOM), $end->format(\DATE_ATOM)],
            [ParameterType::STRING, ParameterType::STRING],
        );

        usort($rows, static fn (array $a, array $b): int => [-(int) $a['revenue_cents'], $a['title'], (int) $a['id']]
            <=> [-(int) $b['revenue_cents'], $b['title'], (int) $b['id']]);

        $lines = [self::HEADER];
        foreach ($rows as $row) {
            $lines[] = [$row['title'], (string) (int) $row['quantity'], (string) (int) $row['revenue_cents']];
        }

        return implode('', array_map(static fn (array $fields): string => implode(',', array_map(self::field(...), $fields))."\r\n", $lines));
    }

    /**
     * RFC 4180 escaping of one CSV field.
     */
    private static function field(string $value): string
    {
        return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
