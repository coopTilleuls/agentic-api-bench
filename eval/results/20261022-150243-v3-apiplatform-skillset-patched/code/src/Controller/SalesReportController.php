<?php

namespace App\Controller;

use App\JsonApi\Input;
use App\JsonApi\JsonApiException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SalesReportController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = Input::date($request->query->get('from'), 'from');
        $to = Input::date($request->query->get('to'), 'to');
        if ($from > $to) {
            throw JsonApiException::badRequest('from', 'from must not be after to.');
        }

        // Whole days in UTC, bounds included: [from 00:00, day after to 00:00).
        $start = $from.' 00:00:00+00';
        $end = (new \DateTimeImmutable($to.' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:sP');

        $rows = $this->db->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents '
            .'FROM purchase_line pl JOIN purchase p ON p.id = pl.purchase_id JOIN book b ON b.id = pl.book_id '
            .'WHERE p.created_at >= CAST(? AS timestamptz) AND p.created_at < CAST(? AS timestamptz) '
            .'GROUP BY b.id, b.title '
            .'ORDER BY revenue_cents DESC, b.title COLLATE "C" ASC, b.id ASC',
            [$start, $end],
        );

        $csv = self::csvLine(['title', 'quantity', 'revenueCents']);
        foreach ($rows as $row) {
            $csv .= self::csvLine([$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']]);
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from, $to),
        ]);
    }

    /** @param list<string|int> $fields */
    private static function csvLine(array $fields): string
    {
        $cells = array_map(
            static fn (string|int $field): string => preg_match('/[",\r\n]/', (string) $field)
                ? '"'.str_replace('"', '""', (string) $field).'"'
                : (string) $field,
            $fields,
        );

        return implode(',', $cells)."\r\n";
    }
}
