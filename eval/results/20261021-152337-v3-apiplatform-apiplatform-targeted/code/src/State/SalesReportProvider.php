<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\JsonApi\Query;
use App\JsonApi\QueryParameterException;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The books sold between two days (UTC, both included), as a CSV file.
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
            throw new QueryParameterException('from', 'The from date must not be after the to date.');
        }

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
            FROM purchase_line pl
            JOIN purchase p ON p.id = pl.purchase_id
            JOIN book b ON b.id = pl.book_id
            WHERE p.created_at >= :from AND p.created_at < :until
            GROUP BY b.id, b.title
            ORDER BY revenue_cents DESC, b.title ASC, b.id ASC
            SQL, [
            'from' => $from->format('Y-m-d H:i:sP'),
            'until' => $to->modify('+1 day')->format('Y-m-d H:i:sP'),
        ]);

        $lines = [['title', 'quantity', 'revenueCents']];
        foreach ($rows as $row) {
            $lines[] = [$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']];
        }

        return new Response(
            implode('', array_map(static fn (array $line): string => implode(',', array_map(self::field(...), $line))."\r\n", $lines)),
            Response::HTTP_OK,
            ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="sales.csv"'],
        );
    }

    /**
     * RFC 4180: a field holding a comma, a quote or a line break is quoted, quotes doubled.
     */
    private static function field(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
