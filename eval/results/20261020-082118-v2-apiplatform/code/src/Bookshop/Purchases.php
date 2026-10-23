<?php

namespace App\Bookshop;

use App\Http\ApiError;
use App\Http\Input;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Customers' purchases, the purchase transaction and the public sales report.
 */
final class Purchases
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentGateway $payment,
    ) {
    }

    /**
     * Identifies the customer from the Customer-Id header; 401 when absent or unknown.
     */
    public function customerId(Request $request): int
    {
        $header = $request->headers->get('Customer-Id');
        $id = null === $header ? false : filter_var($header, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => Input::MAX_INT]]);
        if (false === $id || 0 === (int) $this->db->fetchOne('SELECT COUNT(*) FROM customer WHERE id = ?', [$id])) {
            throw new ApiError(401, 'A valid Customer-Id header is required.');
        }

        return $id;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function list(int $customerId, int $page, int $perPage): array
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, created_at, total_cents FROM purchase
             WHERE customer_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ? OFFSET ?',
            [$customerId, $perPage, ($page - 1) * $perPage],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER],
        );

        return [
            'items' => array_map(fn (array $row): array => $this->purchaseShape($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null null when the purchase does not exist or belongs to another customer
     */
    public function find(int $customerId, int $id): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$id, $customerId],
        );

        return false === $row ? null : $this->purchaseShape($row);
    }

    /**
     * Buys the lines, all or nothing.
     *
     * @param list<array{bookId: int, quantity: int}> $lines already validated for shape
     *
     * @return array<string, mixed> the stored purchase
     *
     * @throws ApiError 422 unknown or unpublished book, 409 not enough stock, 402 declined, 502 payment failure
     */
    public function create(int $customerId, array $lines): array
    {
        $wanted = [];
        foreach ($lines as $line) {
            $wanted[$line['bookId']] = ($wanted[$line['bookId']] ?? 0) + $line['quantity'];
        }

        $this->db->beginTransaction();
        try {
            $books = [];
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, title, price_cents, stock, CASE WHEN published THEN 1 ELSE 0 END AS published
                 FROM book WHERE id IN (?) ORDER BY id FOR UPDATE',
                [array_keys($wanted)],
                [ArrayParameterType::INTEGER],
            );
            foreach ($rows as $row) {
                $books[(int) $row['id']] = $row;
            }

            foreach ($wanted as $bookId => $quantity) {
                if (!isset($books[$bookId]) || 1 !== (int) $books[$bookId]['published']) {
                    throw new ApiError(422, sprintf('Book %d is not available.', $bookId));
                }
            }
            foreach ($wanted as $bookId => $quantity) {
                if ((int) $books[$bookId]['stock'] < $quantity) {
                    throw new ApiError(409, sprintf('Not enough stock for book %d.', $bookId));
                }
            }

            $total = 0;
            $priced = [];
            foreach ($lines as $line) {
                $unitPrice = (int) $books[$line['bookId']]['price_cents'];
                $total += $unitPrice * $line['quantity'];
                $priced[] = ['bookId' => $line['bookId'], 'title' => $books[$line['bookId']]['title'], 'quantity' => $line['quantity'], 'unitPriceCents' => $unitPrice];
            }

            if (!$this->payment->charge($customerId, $total)) {
                throw new ApiError(402, 'The payment was declined.');
            }

            $purchase = $this->db->fetchAssociative(
                'INSERT INTO purchase (customer_id, total_cents) VALUES (?, ?) RETURNING id, created_at, total_cents',
                [$customerId, $total],
            );
            foreach ($priced as $line) {
                $this->db->insert('purchase_line', [
                    'purchase_id' => $purchase['id'],
                    'book_id' => $line['bookId'],
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => $line['unitPriceCents'],
                ]);
            }
            foreach ($wanted as $bookId => $quantity) {
                $this->db->executeStatement('UPDATE book SET stock = stock - ? WHERE id = ?', [$quantity, $bookId]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->isTransactionActive()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return [
            'id' => (int) $purchase['id'],
            'createdAt' => Input::timestamp($purchase['created_at']),
            'totalCents' => (int) $purchase['total_cents'],
            'lines' => $priced,
        ];
    }

    /**
     * Books sold between two whole UTC days, both included, best sellers first.
     *
     * @return list<array{bookId: int, title: string, quantity: int, revenueCents: int}>
     */
    public function salesReport(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, SUM(pl.quantity) AS quantity, SUM(pl.quantity * pl.unit_price_cents) AS revenue_cents
             FROM purchase_line pl
             JOIN purchase p ON p.id = pl.purchase_id
             JOIN book b ON b.id = pl.book_id
             WHERE p.created_at >= CAST(? AS timestamptz) AND p.created_at < CAST(? AS timestamptz)
             GROUP BY b.id, b.title',
            [$from->format('Y-m-d 00:00:00+00'), $to->modify('+1 day')->format('Y-m-d 00:00:00+00')],
        );

        $report = array_map(static fn (array $row): array => [
            'bookId' => (int) $row['id'],
            'title' => $row['title'],
            'quantity' => (int) $row['quantity'],
            'revenueCents' => (int) $row['revenue_cents'],
        ], $rows);

        usort($report, static fn (array $a, array $b): int => $b['revenueCents'] <=> $a['revenueCents']
            ?: strcmp($a['title'], $b['title'])
            ?: $a['bookId'] <=> $b['bookId']);

        return $report;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function purchaseShape(array $row): array
    {
        $lines = $this->db->fetchAllAssociative(
            'SELECT pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id = ? ORDER BY pl.id',
            [$row['id']],
        );

        return [
            'id' => (int) $row['id'],
            'createdAt' => Input::timestamp($row['created_at']),
            'totalCents' => (int) $row['total_cents'],
            'lines' => array_map(static fn (array $line): array => [
                'bookId' => (int) $line['book_id'],
                'title' => $line['title'],
                'quantity' => (int) $line['quantity'],
                'unitPriceCents' => (int) $line['unit_price_cents'],
            ], $lines),
        ];
    }
}
