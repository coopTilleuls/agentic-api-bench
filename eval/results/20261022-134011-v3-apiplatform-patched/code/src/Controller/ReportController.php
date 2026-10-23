<?php

namespace App\Controller;

use App\JsonApi\ApiException;
use App\JsonApi\Query;
use App\Repository\ReportRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController
{
    public function __construct(private readonly ReportRepository $reports)
    {
    }

    #[Route('/reports/sales', name: 'reports_sales', methods: ['GET'])]
    public function sales(Request $request): Response
    {
        $from = Query::requiredDate($request, 'from');
        $to = Query::requiredDate($request, 'to');
        if ($from > $to) {
            throw ApiException::parameter('from', 'from must not be after to.');
        }

        // Whole days in UTC, both bounds included.
        $start = new \DateTimeImmutable($from.' 00:00:00', new \DateTimeZone('UTC'));
        $end = (new \DateTimeImmutable($to.' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day');

        $csv = self::csvLine(['title', 'quantity', 'revenueCents']);
        foreach ($this->reports->salesBetween($start, $end) as $row) {
            $csv .= self::csvLine([$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']]);
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from, $to),
        ]);
    }

    /**
     * One RFC 4180 record, CRLF terminated.
     *
     * @param list<string|int> $fields
     */
    private static function csvLine(array $fields): string
    {
        $cells = array_map(static function (string|int $field): string {
            $value = (string) $field;

            return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
        }, $fields);

        return implode(',', $cells)."\r\n";
    }
}
