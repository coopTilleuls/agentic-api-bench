<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiException;
use App\Api\QueryParams;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Books sold between two days (UTC, both included), as RFC 4180 CSV.
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
        WHERE p.created_at >= :start AND p.created_at < :end
        GROUP BY b.id, b.title
        ORDER BY revenue_cents DESC, b.title ASC, b.id ASC
        SQL;

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        /** @var Request $request */
        $request = $context['request'];
        $from = QueryParams::requiredDate($request, 'from');
        $to = QueryParams::requiredDate($request, 'to');

        if ($from > $to) {
            throw ApiException::status(400, 'from must not be after to.', parameter: 'from');
        }

        $rows = $this->entityManager->getConnection()->fetchAllAssociative(self::SQL, [
            'start' => $from->format('Y-m-d H:i:sP'),
            'end' => $to->modify('+1 day')->format('Y-m-d H:i:sP'),
        ]);

        $lines = ['title,quantity,revenueCents'];
        foreach ($rows as $row) {
            $lines[] = implode(',', [self::field((string) $row['title']), (int) $row['quantity'], (int) $row['revenue_cents']]);
        }

        return new Response(implode("\r\n", $lines)."\r\n", Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    /**
     * RFC 4180: a field containing a comma, a quote or a line break is quoted, quotes doubled.
     */
    private static function field(string $value): string
    {
        return preg_match('/[",\r\n]/', $value) ? '"'.str_replace('"', '""', $value).'"' : $value;
    }
}
