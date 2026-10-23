<?php

namespace App\Controller;

use App\Bookshop\Store;
use App\JsonApi\Document;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParams;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SalesReportAction
{
    public function __construct(private readonly Store $store)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = $this->day($request, 'from');
        $to = $this->day($request, 'to');
        if ($from > $to) {
            throw JsonApiException::create(400, 'Invalid parameter', 'from must not be after to.', null, 'from');
        }

        $lines = ['title,quantity,revenueCents'];
        foreach ($this->store->salesBetween($from, $to->modify('+1 day')) as $row) {
            $lines[] = implode(',', [self::csv((string) $row['title']), (int) $row['quantity'], (int) $row['revenue_cents']]);
        }

        return new Response(
            implode("\r\n", $lines)."\r\n",
            200,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="sales-report.csv"',
            ],
        );
    }

    /**
     * A required YYYY-MM-DD query parameter, as the start of that day in UTC.
     */
    private function day(Request $request, string $name): \DateTimeImmutable
    {
        $value = $request->query->get($name);
        if (!is_string($value) || !QueryParams::isDate($value)) {
            throw JsonApiException::create(400, 'Invalid parameter', sprintf('%s is required and must be a date YYYY-MM-DD.', $name), null, $name);
        }

        return new \DateTimeImmutable($value.' 00:00:00', new \DateTimeZone('UTC'));
    }

    /**
     * RFC 4180 field escaping.
     */
    private static function csv(string $value): string
    {
        if (1 === preg_match('/[",\r\n]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
