<?php

namespace App\Report;

use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sales of the books between two dates (UTC, bounds included), as CSV (RFC 4180).
 */
final class SalesReportController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QueryParser $query,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $from = $this->query->date($request->query->all()['from'] ?? null, 'from');
        $to = $this->query->date($request->query->all()['to'] ?? null, 'to');
        if ($from > $to) {
            throw JsonApiException::error(400, 'The from parameter must not be after the to parameter.', ['parameter' => 'from']);
        }

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT b.title AS title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= :from AND p.created_at < :to
             GROUP BY b.id, b.title
             ORDER BY revenue_cents DESC, b.title ASC, b.id ASC',
            [
                'from' => $from->format('Y-m-d').' 00:00:00+00',
                'to' => $to->modify('+1 day')->format('Y-m-d').' 00:00:00+00',
            ],
        );

        $csv = "title,quantity,revenueCents\r\n";
        foreach ($rows as $row) {
            $csv .= self::csvField((string) $row['title']).','.(int) $row['quantity'].','.(int) $row['revenue_cents']."\r\n";
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="sales-%s-%s.csv"', $from->format('Y-m-d'), $to->format('Y-m-d')),
        ]);
    }

    private static function csvField(string $value): string
    {
        if (1 !== preg_match('/[",\r\n]/', $value)) {
            return $value;
        }

        return '"'.str_replace('"', '""', $value).'"';
    }
}
