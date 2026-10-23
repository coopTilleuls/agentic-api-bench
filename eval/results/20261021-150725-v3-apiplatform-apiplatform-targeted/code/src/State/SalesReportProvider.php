<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\Error\JsonApiException;
use App\Api\Query\ListQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /reports/sales?from=&to=: the books sold between two days (UTC, both included), as a CSV file.
 *
 * @implements ProviderInterface<object>
 */
final class SalesReportProvider implements ProviderInterface
{
    private const SQL = <<<'SQL'
        SELECT b.id AS book_id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
        FROM purchase_line pl
        JOIN purchase p ON p.id = pl.purchase_id
        JOIN book b ON b.id = pl.book_id
        WHERE p.created_at >= :from AND p.created_at < :to
        GROUP BY b.id, b.title
        ORDER BY revenue_cents DESC, b.title ASC, b.id ASC
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ListQuery $query,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $from = $this->query->date('from', true);
        $to = $this->query->date('to', true);

        if ($from > $to) {
            throw JsonApiException::single(400, '"from" must not be after "to".', null, 'from');
        }

        $rows = $this->connection->fetchAllAssociative(self::SQL, [
            'from' => $from->format('Y-m-d 00:00:00+00'),
            'to' => $to->modify('+1 day')->format('Y-m-d 00:00:00+00'),
        ]);

        $lines = [['title', 'quantity', 'revenueCents']];

        foreach ($rows as $row) {
            $lines[] = [$row['title'], (int) $row['quantity'], (int) $row['revenue_cents']];
        }

        $body = implode('', array_map(
            fn (array $fields): string => implode(',', array_map($this->csvField(...), $fields))."\r\n",
            $lines,
        ));

        return new Response($body, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="sales-report.csv"',
        ]);
    }

    /**
     * RFC 4180: a field containing a comma, a quote or a line break is quoted, and its quotes are doubled.
     */
    private function csvField(int|string $value): string
    {
        $value = (string) $value;

        if (preg_match('/[",\r\n]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
