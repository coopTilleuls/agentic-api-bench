<?php

namespace App\Controller;

use App\Bookshop\BookshopRepository;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    #[Route('/reports/sales', name: 'reports_sales', methods: ['GET'])]
    public function sales(Request $request): Response
    {
        $from = $this->dateParameter($request, 'from');
        $to = $this->dateParameter($request, 'to');
        if ($from > $to) {
            throw JsonApiException::one(400, 'Invalid query parameter', 'from must not be after to.', parameter: 'from');
        }

        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($from.' 00:00:00', $utc);
        $end = (new \DateTimeImmutable($to.' 00:00:00', $utc))->modify('+1 day');

        $csv = "title,quantity,revenueCents\r\n";
        foreach ($this->repository->salesBetween($start->format('Y-m-d H:i:sP'), $end->format('Y-m-d H:i:sP')) as $row) {
            $csv .= implode(',', [
                self::csvField((string) $row['title']),
                (int) $row['quantity'],
                (int) $row['revenue_cents'],
            ])."\r\n";
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from, $to),
        ]);
    }

    private function dateParameter(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;
        $date = is_string($value) ? JsonApi::date($value) : null;
        if (null === $date) {
            throw JsonApiException::one(400, 'Invalid query parameter', "$name must be a date (YYYY-MM-DD).", parameter: $name);
        }

        return $date;
    }

    /**
     * RFC 4180: quote fields holding a comma, a double quote or a line break, doubling the quotes.
     */
    private static function csvField(string $value): string
    {
        return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
