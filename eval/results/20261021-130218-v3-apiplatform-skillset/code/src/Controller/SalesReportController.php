<?php

namespace App\Controller;

use App\Http\ApiException;
use App\Http\QueryParser;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SalesReportController
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    /**
     * Public CSV report of the books sold between two days (UTC, both bounds included).
     */
    #[Route('/reports/sales', name: 'sales_report', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $from = $this->day($request, 'from');
        $to = $this->day($request, 'to');
        if ($from > $to) {
            throw ApiException::single(400, 'The "from" date must not be after the "to" date.', ['parameter' => 'from']);
        }

        $lines = ['title,quantity,revenueCents'];
        foreach ($this->repository->salesReport($from, $to->modify('+1 day')) as $row) {
            $lines[] = implode(',', [self::csvField($row['title']), $row['quantity'], $row['revenueCents']]);
        }

        return new Response(
            implode("\r\n", $lines)."\r\n",
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from->format('Y-m-d'), $to->format('Y-m-d')),
            ],
        );
    }

    private function day(Request $request, string $parameter): \DateTimeImmutable
    {
        $value = $request->query->all()[$parameter] ?? null;
        if (!is_string($value) || null === ($day = QueryParser::date($value))) {
            throw ApiException::single(400, sprintf('The "%s" parameter is required and must be a date (YYYY-MM-DD).', $parameter), ['parameter' => $parameter]);
        }

        return $day;
    }

    /**
     * RFC 4180: a field is quoted only when it contains a comma, a double quote or a line break.
     */
    private static function csvField(string $value): string
    {
        return 1 === preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
