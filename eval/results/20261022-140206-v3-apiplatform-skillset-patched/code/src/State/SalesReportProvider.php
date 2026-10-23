<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiQuery;
use App\Repository\SalesReportRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves GET /reports/sales: the books sold between two days (UTC, both included) as a CSV file.
 *
 * @implements ProviderInterface<Response>
 */
final class SalesReportProvider implements ProviderInterface
{
    public function __construct(private readonly SalesReportRepository $sales)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        /** @var Request $request */
        $request = $context['request'];

        $from = $this->dateParameter($request, 'from');
        $to = $this->dateParameter($request, 'to');
        if ($from > $to) {
            throw JsonApiException::create(400, 'The "from" date must not be after the "to" date.', null, 'from');
        }

        $utc = new \DateTimeZone('UTC');
        $rows = $this->sales->soldBetween(
            new \DateTimeImmutable("$from 00:00:00", $utc),
            (new \DateTimeImmutable("$to 00:00:00", $utc))->modify('+1 day'),
        );

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['title', 'quantity', 'revenueCents'], ',', '"', '\\', "\r\n");
        foreach ($rows as $row) {
            fputcsv($csv, [$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']], ',', '"', '\\', "\r\n");
        }
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);

        return new Response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales.csv"',
        ]);
    }

    private function dateParameter(Request $request, string $name): string
    {
        $raw = $request->query->all()[$name] ?? null;
        if (null === $raw) {
            throw JsonApiException::create(400, \sprintf('The "%s" parameter is required.', $name), null, $name);
        }
        if (!\is_string($raw)) {
            throw JsonApiException::create(400, \sprintf('The "%s" parameter must be a date formatted YYYY-MM-DD.', $name), null, $name);
        }

        return JsonApiQuery::date($raw, $name);
    }
}
