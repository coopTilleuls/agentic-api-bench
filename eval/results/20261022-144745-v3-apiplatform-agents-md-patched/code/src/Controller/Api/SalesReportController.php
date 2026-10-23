<?php

namespace App\Controller\Api;

use App\Bookshop\SalesReport;
use App\JsonApi\JsonApiException;
use App\JsonApi\ListParameters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SalesReportController
{
    public function __construct(private readonly SalesReport $report)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = $this->date($request, 'from');
        $to = $this->date($request, 'to');

        if ($from > $to) {
            throw JsonApiException::badRequest('"from" must not be after "to".', 'from');
        }

        return new Response($this->report->csv($from, $to), Response::HTTP_OK, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from->format('Y-m-d'), $to->format('Y-m-d')),
        ]);
    }

    private function date(Request $request, string $name): \DateTimeImmutable
    {
        $raw = $request->query->all()[$name] ?? null;

        if (!\is_string($raw) || !ListParameters::isDate($raw)) {
            throw JsonApiException::badRequest(sprintf('"%s" is required and must be a date (YYYY-MM-DD).', $name), $name);
        }

        return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
    }
}
