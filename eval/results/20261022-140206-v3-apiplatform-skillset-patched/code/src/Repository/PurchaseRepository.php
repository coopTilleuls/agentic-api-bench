<?php

namespace App\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads and stores purchases and checks customers.
 */
final class PurchaseRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function customerExists(int $customerId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM customer WHERE id = :id', ['id' => $customerId]);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function findPageForCustomer(int $customerId, int $offset, int $limit): array
    {
        $items = $this->connection->fetchAllAssociative(
            'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.customer_id = :customerId ORDER BY p.created_at DESC, p.id DESC LIMIT :limit OFFSET :offset',
            ['customerId' => $customerId, 'limit' => $limit, 'offset' => $offset],
            ['customerId' => ParameterType::INTEGER, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM purchase p WHERE p.customer_id = :customerId',
            ['customerId' => $customerId],
            ['customerId' => ParameterType::INTEGER],
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, mixed>|null the purchase, when it belongs to the customer
     */
    public function findForCustomer(int $purchaseId, int $customerId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.id, p.created_at, p.total_cents FROM purchase p WHERE p.id = :id AND p.customer_id = :customerId',
            ['id' => $purchaseId, 'customerId' => $customerId],
        );

        return false === $row ? null : $row;
    }

    /**
     * Lines of several purchases, grouped by purchase id.
     *
     * @param list<int> $purchaseIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function findLines(array $purchaseIds): array
    {
        if ([] === $purchaseIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT l.purchase_id, l.book_id, b.title, l.quantity, l.unit_price_cents FROM purchase_line l JOIN book b ON b.id = l.book_id WHERE l.purchase_id IN (:ids) ORDER BY l.id',
            ['ids' => $purchaseIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $lines = [];
        foreach ($rows as $row) {
            $lines[(int) $row['purchase_id']][] = $row;
        }

        return $lines;
    }

    /**
     * @param list<array{bookId: int, quantity: int, unitPriceCents: int}> $lines
     */
    public function insert(int $customerId, array $lines, int $totalCents): int
    {
        $purchaseId = (int) $this->connection->fetchOne(
            'INSERT INTO purchase (customer_id, created_at, total_cents) VALUES (:customerId, now(), :totalCents) RETURNING id',
            ['customerId' => $customerId, 'totalCents' => $totalCents],
            ['customerId' => ParameterType::INTEGER, 'totalCents' => ParameterType::INTEGER],
        );

        foreach ($lines as $line) {
            $this->connection->executeStatement(
                'INSERT INTO purchase_line (purchase_id, book_id, quantity, unit_price_cents) VALUES (:purchaseId, :bookId, :quantity, :unitPriceCents)',
                ['purchaseId' => $purchaseId, 'bookId' => $line['bookId'], 'quantity' => $line['quantity'], 'unitPriceCents' => $line['unitPriceCents']],
                ['purchaseId' => ParameterType::INTEGER, 'bookId' => ParameterType::INTEGER, 'quantity' => ParameterType::INTEGER, 'unitPriceCents' => ParameterType::INTEGER],
            );
        }

        return $purchaseId;
    }
}
