<?php

namespace App\Controller;

use App\Http\JsonApiException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public CSV report of the books sold between two days (UTC, both bounds included), all customers together.
 */
final class SalesReportController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = $this->day($request, 'from');
        $to = $this->day($request, 'to');
        if ($from > $to) {
            throw new JsonApiException(400, [[
                'status' => '400',
                'title' => 'Invalid query parameter',
                'detail' => 'from must not be after to.',
                'source' => ['parameter' => 'from'],
            ]]);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE (p.created_at AT TIME ZONE \'UTC\')::date BETWEEN CAST(:from AS date) AND CAST(:to AS date)
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            ['from' => $from, 'to' => $to],
        );

        $lines = [['title', 'quantity', 'revenueCents']];
        foreach ($rows as $row) {
            $lines[] = [(string) $row['title'], (string) $row['quantity'], (string) $row['revenue_cents']];
        }
        $csv = implode('', array_map(
            static fn (array $fields) => implode(',', array_map(self::csvField(...), $fields))."\r\n",
            $lines,
        ));

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => \sprintf('attachment; filename="sales-%s-%s.csv"', $from, $to),
        ]);
    }

    private function day(Request $request, string $name): string
    {
        $value = $request->query->get($name);
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new JsonApiException(400, [[
                'status' => '400',
                'title' => 'Invalid query parameter',
                'detail' => \sprintf('%s is required and must be a date as YYYY-MM-DD.', $name),
                'source' => ['parameter' => $name],
            ]]);
        }

        return $value;
    }

    /**
     * RFC 4180: fields with a comma, a double quote or a line break are quoted, quotes doubled.
     */
    private static function csvField(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
