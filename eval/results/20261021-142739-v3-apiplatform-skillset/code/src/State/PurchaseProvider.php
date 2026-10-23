<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Purchase;
use App\JsonApi\CustomerIdentificationListener;
use App\JsonApi\JsonApiException;
use App\JsonApi\Paginator;
use App\JsonApi\Query;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<Purchase>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];
        $customerId = (int) $request->attributes->get(CustomerIdentificationListener::ATTRIBUTE);

        if ($operation instanceof CollectionOperationInterface) {
            Query::include($request, []);
            [$number, $size] = Query::page($request);

            $total = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
                [$customerId, $size, ($number - 1) * $size],
            );

            return new Paginator($this->withLines($rows), $total, $number, $size);
        }

        Query::include($request, []);
        $row = $this->connection->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [(int) $uriVariables['id'], $customerId],
        );
        if (false === $row) {
            throw JsonApiException::single(404, 'Not Found', sprintf('Purchase %d does not exist.', $uriVariables['id']));
        }

        return $this->withLines([$row])[0];
    }

    /**
     * @param list<array<string, mixed>> $rows the purchases, with id, created_at and total_cents
     *
     * @return list<Purchase>
     */
    public function withLines(array $rows): array
    {
        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $lines = $this->connection->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (?) ORDER BY pl.id',
            [$ids],
            [ArrayParameterType::INTEGER],
        );

        $linesByPurchase = [];
        foreach ($lines as $line) {
            $linesByPurchase[(int) $line['purchase_id']][] = [
                'bookId' => (int) $line['book_id'],
                'title' => $line['title'],
                'quantity' => (int) $line['quantity'],
                'unitPriceCents' => (int) $line['unit_price_cents'],
            ];
        }

        return array_map(static function (array $row) use ($linesByPurchase): Purchase {
            $purchase = new Purchase();
            $purchase->id = (int) $row['id'];
            $purchase->createdAt = self::timestamp($row['created_at']);
            $purchase->totalCents = (int) $row['total_cents'];
            $purchase->lines = $linesByPurchase[$purchase->id] ?? [];

            return $purchase;
        }, $rows);
    }

    /**
     * An ISO 8601 timestamp in UTC.
     */
    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
