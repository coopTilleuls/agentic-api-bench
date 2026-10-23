<?php

namespace App\Controller;

use App\Http\JsonApiException;
use App\Http\QueryParameters;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public sales report: the books sold between two days (UTC, both bounds included), as RFC 4180 CSV.
 */
#[Route('/reports/sales', name: 'bookshop_sales_report', methods: ['GET'])]
final class SalesReportController
{
    public function __construct(
        private readonly BookshopRepository $repository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $from = self::date($request, 'from');
        $to = self::date($request, 'to');
        if ($from > $to) {
            throw JsonApiException::parameter(400, 'from', 'The from date must not be after the to date.');
        }

        $utc = new \DateTimeZone('UTC');
        $rows = $this->repository->salesBetween(
            new \DateTimeImmutable($from.' 00:00:00', $utc),
            (new \DateTimeImmutable($to.' 00:00:00', $utc))->modify('+1 day'),
        );

        $lines = [['title', 'quantity', 'revenueCents']];
        foreach ($rows as $row) {
            $lines[] = [$row['title'], (string) $row['quantity'], (string) $row['revenue_cents']];
        }
        $csv = implode('', array_map(
            static fn (array $fields): string => implode(',', array_map(self::csvField(...), $fields))."\r\n",
            $lines,
        ));

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales.csv"',
        ]);
    }

    private static function date(Request $request, string $name): string
    {
        $value = $request->query->get($name);
        if (null === $value) {
            throw JsonApiException::parameter(400, $name, "The $name parameter is required.");
        }
        if (!\is_string($value) || !QueryParameters::isDate($value)) {
            throw JsonApiException::parameter(400, $name, "The $name parameter must be a date (YYYY-MM-DD).");
        }

        return $value;
    }

    /**
     * RFC 4180: a field is quoted when it holds a comma, a double quote or a line break; quotes are doubled.
     */
    private static function csvField(string $field): string
    {
        if (1 !== preg_match('/[",\r\n]/', $field)) {
            return $field;
        }

        return '"'.str_replace('"', '""', $field).'"';
    }
}
