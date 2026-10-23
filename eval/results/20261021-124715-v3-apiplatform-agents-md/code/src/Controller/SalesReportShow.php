<?php

namespace App\Controller;

use App\Bookshop\SalesReport;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SalesReportShow
{
    public function __construct(private readonly SalesReport $report)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = $this->day($request, 'from');
        $to = $this->day($request, 'to');
        if ($from > $to) {
            throw JsonApiException::badParameter('from', '"from" must not be after "to".');
        }

        $filename = sprintf('sales-%s-%s.csv', $from, $to);

        return new Response($this->report->csv($from, $to), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }

    private function day(Request $request, string $name): string
    {
        $value = $request->query->get($name);
        if (!\is_string($value) || '' === $value) {
            throw JsonApiException::badParameter($name, sprintf('"%s" is required (YYYY-MM-DD).', $name));
        }

        return QueryParams::date($value, $name);
    }
}
