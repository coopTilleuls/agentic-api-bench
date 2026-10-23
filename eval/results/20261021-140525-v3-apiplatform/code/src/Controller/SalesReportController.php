<?php

namespace App\Controller;

use App\Exception\ApiException;
use App\Http\JsonApiQuery;
use App\Service\BookshopRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SalesReportController
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    #[Route('/reports/sales', name: 'sales_report', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');
        if ($from > $to) {
            throw ApiException::badParameter('from', 'from must not be after to.');
        }

        $rows = $this->repository->salesBetween(
            new \DateTimeImmutable($from . ' 00:00:00', new \DateTimeZone('UTC')),
            (new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day'),
        );

        $lines = ['title,quantity,revenueCents'];
        foreach ($rows as $row) {
            $lines[] = implode(',', [self::csv((string) $row['title']), (int) $row['quantity'], (int) $row['revenue_cents']]);
        }

        return new Response(
            implode("\r\n", $lines) . "\r\n",
            200,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from, $to),
            ],
        );
    }

    private function date(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;
        if (!\is_string($value) || !JsonApiQuery::isDate($value)) {
            throw ApiException::badParameter($name, sprintf('%s is required and must be a date (YYYY-MM-DD).', $name));
        }

        return $value;
    }

    /**
     * Quotes a field of an RFC 4180 CSV line when needed.
     */
    private static function csv(string $field): string
    {
        if (preg_match('/[",\r\n]/', $field)) {
            return '"' . str_replace('"', '""', $field) . '"';
        }

        return $field;
    }
}
