<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Http\JsonApiError;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales report as CSV (RFC 4180). Returns a Response, so the serializer is bypassed.
 */
final class SalesReportProvider implements ProviderInterface
{
    private const SQL = <<<'SQL'
        SELECT b.title AS title,
               SUM(pl.quantity) AS quantity,
               SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
        FROM purchase_line pl
        JOIN purchase p ON p.id = pl.purchase_id
        JOIN book b ON b.id = pl.book_id
        WHERE p.created_at >= :from AND p.created_at < :to
        GROUP BY b.id, b.title
        ORDER BY revenue_cents DESC, b.title ASC, b.id ASC
        SQL;

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $query = $this->requestStack->getCurrentRequest()->query->all();
        $from = $query['from'] ?? null;
        $to = $query['to'] ?? null;

        if (!\is_string($from) || !self::isDay($from)) {
            return JsonApiError::response(400, 'Bad Request', 'The from parameter is required and must be a YYYY-MM-DD date.', parameter: 'from');
        }
        if (!\is_string($to) || !self::isDay($to)) {
            return JsonApiError::response(400, 'Bad Request', 'The to parameter is required and must be a YYYY-MM-DD date.', parameter: 'to');
        }
        if ($from > $to) {
            return JsonApiError::response(400, 'Bad Request', 'The from date must not be after the to date.', parameter: 'from');
        }

        $utc = new \DateTimeZone('UTC');
        $start = new \DateTimeImmutable($from.' 00:00:00', $utc);
        $end = (new \DateTimeImmutable($to.' 00:00:00', $utc))->modify('+1 day');

        $rows = $this->connection->fetchAllAssociative(self::SQL, [
            'from' => $start->format('Y-m-d H:i:sP'),
            'to' => $end->format('Y-m-d H:i:sP'),
        ]);

        $csv = "title,quantity,revenueCents\r\n";
        foreach ($rows as $row) {
            $csv .= self::field((string) $row['title']).','.(int) $row['quantity'].','.(int) $row['revenue_cents']."\r\n";
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => \sprintf('attachment; filename="sales-%s-%s.csv"', $from, $to),
        ]);
    }

    private static function isDay(string $value): bool
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function field(string $value): string
    {
        if (preg_match('/[",\r\n]/', $value)) {
            return '"'.str_replace('"', '""', $value).'"';
        }

        return $value;
    }
}
