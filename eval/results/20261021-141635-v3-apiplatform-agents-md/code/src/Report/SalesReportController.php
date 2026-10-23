<?php

namespace App\Report;

use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParameters;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloads the books sold between two days, as RFC 4180 CSV.
 */
final class SalesReportController
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    public function __invoke(Request $request): Response
    {
        $from = $this->day($request, 'from');
        $to = $this->day($request, 'to');
        if ($from > $to) {
            throw JsonApiException::parameter('from', 'from must not be after to.');
        }

        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($from.' 00:00:00', $utc);
        $end = (new \DateTimeImmutable($to.' 00:00:00', $utc))->modify('+1 day');

        $lines = [['title', 'quantity', 'revenueCents']];
        foreach ($this->repository->salesBetween($start, $end) as $row) {
            $lines[] = [$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']];
        }

        $body = implode('', array_map(static fn (array $fields): string => self::csvLine($fields), $lines));

        return new Response($body, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => \sprintf('attachment; filename="sales-%s-%s.csv"', $from, $to),
        ]);
    }

    /**
     * A required day parameter.
     */
    private function day(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;
        if (null === $value) {
            throw JsonApiException::parameter($name, "The $name parameter is required.");
        }
        if (!QueryParameters::validDate($value)) {
            throw JsonApiException::parameter($name, "$name must be a date as YYYY-MM-DD.");
        }

        return $value;
    }

    /**
     * One RFC 4180 record: fields containing a comma, a quote or a line break are quoted.
     *
     * @param list<mixed> $fields
     */
    private static function csvLine(array $fields): string
    {
        $cells = array_map(static function (mixed $field): string {
            $text = (string) $field;

            return preg_match('/[",\r\n]/', $text) ? '"'.str_replace('"', '""', $text).'"' : $text;
        }, $fields);

        return implode(',', $cells)."\r\n";
    }
}
