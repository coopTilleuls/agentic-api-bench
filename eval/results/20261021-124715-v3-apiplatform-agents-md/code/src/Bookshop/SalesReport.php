<?php

namespace App\Bookshop;

use Doctrine\DBAL\Connection;

/**
 * The sales report: the books sold between two days, as CSV (RFC 4180).
 */
final class SalesReport
{
    private const HEADER = ['title', 'quantity', 'revenueCents'];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param string $from first day included, YYYY-MM-DD
     * @param string $to   last day included, YYYY-MM-DD
     */
    public function csv(string $from, string $to): string
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE (p.created_at AT TIME ZONE \'UTC\')::date BETWEEN CAST(:from AS date) AND CAST(:to AS date)
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title COLLATE "C" ASC, b.id ASC',
            ['from' => $from, 'to' => $to],
        );

        $lines = [self::line(self::HEADER)];
        foreach ($rows as $row) {
            $lines[] = self::line([$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']]);
        }

        return implode('', $lines);
    }

    /**
     * One RFC 4180 record, CRLF terminated.
     *
     * @param list<string|int> $fields
     */
    private static function line(array $fields): string
    {
        return implode(',', array_map(static function (string|int $field): string {
            $value = (string) $field;
            if (preg_match('/[",\r\n]/', $value)) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }

            return $value;
        }, $fields)) . "\r\n";
    }
}
