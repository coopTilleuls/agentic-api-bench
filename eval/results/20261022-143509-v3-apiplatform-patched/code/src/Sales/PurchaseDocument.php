<?php

namespace App\Sales;

final class PurchaseDocument
{
    /**
     * @param array<string, mixed>        $row   id, created_at, total_cents
     * @param list<array<string, mixed>>  $lines book_id, title, quantity, unit_price_cents
     *
     * @return array<string, mixed>
     */
    public static function resource(array $row, array $lines, string $baseUrl): array
    {
        return [
            'type' => 'purchases',
            'id' => (string) $row['id'],
            'attributes' => [
                'createdAt' => self::timestamp($row['created_at']),
                'totalCents' => (int) $row['total_cents'],
                'lines' => array_map(static fn (array $line): array => [
                    'bookId' => (int) $line['book_id'],
                    'title' => $line['title'],
                    'quantity' => (int) $line['quantity'],
                    'unitPriceCents' => (int) $line['unit_price_cents'],
                ], $lines),
            ],
            'links' => ['self' => $baseUrl.'/purchases/'.$row['id']],
        ];
    }

    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
