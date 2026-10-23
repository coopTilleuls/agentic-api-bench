<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiProblem;
use App\Api\ListQuery;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /reports/sales?from=&to=: books sold between two days (UTC, both included), as RFC 4180 CSV.
 */
final class SalesReportProvider implements ProviderInterface
{
    private const SQL = <<<'SQL'
        SELECT b.title AS title,
               SUM(pl.quantity) AS quantity,
               SUM(pl.quantity * pl.unit_price_cents) AS revenue
          FROM purchase_line pl
          JOIN purchase p ON p.id = pl.purchase_id
          JOIN book b ON b.id = pl.book_id
         WHERE p.created_at >= :from AND p.created_at < :until
         GROUP BY b.id, b.title
         ORDER BY revenue DESC, b.title ASC, b.id ASC
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly ListQuery $query,
        private readonly RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $request = $this->requests->getCurrentRequest();
        $from = $this->query->requiredDate($request, 'from');
        $to = $this->query->requiredDate($request, 'to');
        if ($from > $to) {
            throw ApiProblem::single(400, 'from must not be after to.', parameter: 'from');
        }

        $rows = $this->connection->fetchAllAssociative(self::SQL, [
            'from' => $from->format('Y-m-d').' 00:00:00+00',
            'until' => $to->modify('+1 day')->format('Y-m-d').' 00:00:00+00',
        ]);

        $lines = ['title,quantity,revenueCents'];
        foreach ($rows as $row) {
            $lines[] = implode(',', [self::csvField((string) $row['title']), (int) $row['quantity'], (int) $row['revenue']]);
        }

        return new Response(
            implode("\r\n", $lines)."\r\n",
            Response::HTTP_OK,
            ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="sales.csv"'],
        );
    }

    private static function csvField(string $value): string
    {
        return preg_match('/[,"\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
