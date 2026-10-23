<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Books sold between two days (UTC, both included), as RFC 4180 CSV:
 * revenue descending, then title, then book id.
 */
final class SalesReportProvider implements ProviderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $request = $this->requestStack->getCurrentRequest();
        $from = new \DateTimeImmutable($request->query->getString('from').' 00:00:00', new \DateTimeZone('UTC'));
        $to = (new \DateTimeImmutable($request->query->getString('to').' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day');

        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.id AS book_id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= :from AND p.created_at < :to
             GROUP BY b.id, b.title',
            ['from' => $from->format('Y-m-d H:i:sP'), 'to' => $to->format('Y-m-d H:i:sP')],
        );

        $rows = array_map(static fn (array $row): array => [
            'bookId' => (int) $row['book_id'],
            'title' => (string) $row['title'],
            'quantity' => (int) $row['quantity'],
            'revenueCents' => (int) $row['revenue_cents'],
        ], $rows);

        usort($rows, static fn (array $a, array $b): int => $b['revenueCents'] <=> $a['revenueCents']
            ?: strcmp($a['title'], $b['title'])
            ?: $a['bookId'] <=> $b['bookId']);

        $lines = ['title,quantity,revenueCents'];
        foreach ($rows as $row) {
            $lines[] = implode(',', [self::escape($row['title']), $row['quantity'], $row['revenueCents']]);
        }

        return new Response(
            implode("\r\n", $lines)."\r\n",
            Response::HTTP_OK,
            ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="sales.csv"'],
        );
    }

    private static function escape(string $field): string
    {
        if (1 === preg_match('/[",\r\n]/', $field)) {
            return '"'.str_replace('"', '""', $field).'"';
        }

        return $field;
    }
}
