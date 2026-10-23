<?php

namespace App\Controller;

use App\Bookshop\Purchases;
use App\Http\ApiError;
use App\Http\Input;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SalesReportController
{
    public function __construct(private readonly Purchases $purchases)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = Input::optionalString($request, 'from');
        $to = Input::optionalString($request, 'to');
        if (null === $from || null === $to) {
            throw new ApiError(400, '"from" and "to" are required.');
        }

        $from = Input::date($from, 'from');
        $to = Input::date($to, 'to');
        if ($from > $to) {
            throw new ApiError(400, '"from" must not be after "to".');
        }

        $lines = ['title,quantity,revenueCents'];
        foreach ($this->purchases->salesReport($from, $to) as $row) {
            $lines[] = implode(',', [self::csv($row['title']), $row['quantity'], $row['revenueCents']]);
        }

        return new Response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="sales.csv"',
        ]);
    }

    private static function csv(string $value): string
    {
        return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
