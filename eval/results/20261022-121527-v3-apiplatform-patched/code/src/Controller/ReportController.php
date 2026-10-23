<?php

namespace App\Controller;

use App\JsonApi\ApiException;
use App\JsonApi\JsonApi;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/reports/sales', name: 'reports_sales', methods: ['GET'])]
    public function sales(Request $request): Response
    {
        $from = JsonApi::dateParameter($request, 'from');
        $to = JsonApi::dateParameter($request, 'to');
        if ($from > $to) {
            throw ApiException::of(400, 'The from date must not be after the to date.', parameter: 'from');
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE CAST(p.created_at AT TIME ZONE \'UTC\' AS date) BETWEEN CAST(:from AS date) AND CAST(:to AS date)
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title COLLATE "C" ASC, b.id ASC',
            ['from' => $from, 'to' => $to],
        );

        $csv = "title,quantity,revenueCents\r\n";
        foreach ($rows as $row) {
            $csv .= self::csvField((string) $row['title']).','.(int) $row['quantity'].','.(int) $row['revenue_cents']."\r\n";
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sales-'.$from.'-'.$to.'.csv"',
        ]);
    }

    /** RFC 4180: quote a field holding a comma, a double quote or a line break. */
    private static function csvField(string $value): string
    {
        return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
