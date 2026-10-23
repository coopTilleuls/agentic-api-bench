<?php

namespace App\Controller\Report;

use App\Http\ApiException;
use App\Http\JsonApi;
use App\Http\QueryParams;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Books sold between two dates (UTC, bounds included), as CSV.
 */
final class SalesReportController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = $this->requiredDate($request, 'from');
        $to = $this->requiredDate($request, 'to');
        if ($from > $to) {
            throw new ApiException(400, 'The from date must not be after the to date.', null, 'from');
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents '
            .'FROM purchase_line pl '
            .'JOIN purchase p ON p.id = pl.purchase_id '
            .'JOIN book b ON b.id = pl.book_id '
            .'WHERE p.created_at >= CAST(:from AS timestamptz) AND p.created_at < CAST(:to AS timestamptz) '
            .'GROUP BY b.id, b.title '
            .'ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            [
                'from' => $from->format('Y-m-d').' 00:00:00+00',
                'to' => $to->modify('+1 day')->format('Y-m-d').' 00:00:00+00',
            ],
        );

        $csv = "title,quantity,revenueCents\r\n";
        foreach ($rows as $row) {
            $csv .= self::field((string) $row['title']).','.(int) $row['quantity'].','.(int) $row['revenue_cents']."\r\n";
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => \sprintf('attachment; filename="sales-%s-%s.csv"', $from->format('Y-m-d'), $to->format('Y-m-d')),
        ]);
    }

    private function requiredDate(Request $request, string $name): \DateTimeImmutable
    {
        $value = $request->query->get($name);
        if (!\is_string($value) || '' === $value) {
            throw QueryParams::badRequest($name, "The $name query parameter is required, formatted YYYY-MM-DD.");
        }
        $date = QueryParams::parseDate($value, $name);

        return new \DateTimeImmutable($date, new \DateTimeZone('UTC'));
    }

    /**
     * RFC 4180: quote a field containing a comma, a quote or a line break.
     */
    private static function field(string $value): string
    {
        if (1 === preg_match('/[",\r\n]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
