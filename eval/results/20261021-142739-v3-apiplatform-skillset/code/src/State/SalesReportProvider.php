<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\JsonApi\JsonApiException;
use App\JsonApi\Query;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The books sold between two days, as a CSV file (RFC 4180).
 */
final class SalesReportProvider implements ProviderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $request = $context['request'];
        $from = Query::requiredDate($request, 'from');
        $to = Query::requiredDate($request, 'to');
        if ($from > $to) {
            throw JsonApiException::badRequest('The from parameter must not be after the to parameter.', 'from');
        }

        // The whole of the last day is included: the period ends before the next day.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= ? AND p.created_at < ?
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            [$from->format('Y-m-d 00:00:00+00'), $to->modify('+1 day')->format('Y-m-d 00:00:00+00')],
        );

        $csv = self::line(['title', 'quantity', 'revenueCents']);
        foreach ($rows as $row) {
            $csv .= self::line([$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']]);
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from->format('Y-m-d'), $to->format('Y-m-d')),
        ]);
    }

    /**
     * @param list<string|int> $fields
     */
    private static function line(array $fields): string
    {
        return implode(',', array_map(static function (string|int $field): string {
            $field = (string) $field;

            return preg_match('/[",\r\n]/', $field) ? '"'.str_replace('"', '""', $field).'"' : $field;
        }, $fields))."\r\n";
    }
}
