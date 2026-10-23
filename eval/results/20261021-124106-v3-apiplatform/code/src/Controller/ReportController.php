<?php

namespace App\Controller;

use App\Http\ApiException;
use App\Http\Query;
use App\Repository\PurchaseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController
{
    public function __construct(private readonly PurchaseRepository $purchases)
    {
    }

    #[Route('/reports/sales', name: 'reports_sales', methods: ['GET'])]
    public function sales(Request $request): Response
    {
        $from = $this->requiredDate($request, 'from');
        $to = $this->requiredDate($request, 'to');
        if ($from > $to) {
            throw ApiException::one(400, 'from must not be after to.', null, 'from');
        }

        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable("$from 00:00:00", $utc);
        $end = (new \DateTimeImmutable("$to 00:00:00", $utc))->modify('+1 day');

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['title', 'quantity', 'revenueCents'], ',', '"', '\\', "\r\n");
        foreach ($this->purchases->salesBetween($start, $end) as $row) {
            fputcsv($handle, [$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']], ',', '"', '\\', "\r\n");
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="sales.csv"',
        ]);
    }

    private function requiredDate(Request $request, string $parameter): string
    {
        $value = $request->query->get($parameter);
        if (!is_string($value)) {
            throw ApiException::one(400, "$parameter is required and must be a date as YYYY-MM-DD.", null, $parameter);
        }

        return Query::date($value, $parameter);
    }
}
