<?php

namespace App\JsonApi;

/**
 * Maps database rows to JSON:API resource objects.
 */
final class ResourceObjects
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function book(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'books',
            'id' => $id,
            'attributes' => [
                'title' => $row['title'],
                'priceCents' => (int) $row['price_cents'],
                'publishedAt' => $row['published_at'],
                'stock' => (int) $row['stock'],
            ],
            'relationships' => [
                'author' => ['data' => ['type' => 'authors', 'id' => (string) $row['author_id']]],
            ],
            'links' => ['self' => "/books/$id"],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>|null $bookIds published book ids, when the books relationship is served
     * @return array<string, mixed>
     */
    public static function author(array $row, ?array $bookIds = null): array
    {
        $id = (string) $row['id'];
        $resource = [
            'type' => 'authors',
            'id' => $id,
            'attributes' => [
                'name' => $row['name'],
                'publishedBooks' => (int) $row['published_books'],
            ],
            'links' => ['self' => "/authors/$id"],
        ];

        if (null !== $bookIds) {
            $resource['relationships'] = [
                'books' => ['data' => array_map(static fn (string $bookId): array => ['type' => 'books', 'id' => $bookId], $bookIds)],
            ];
        }

        return $resource;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public static function purchase(array $row, array $lines): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'purchases',
            'id' => $id,
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
            'links' => ['self' => "/purchases/$id"],
        ];
    }

    /**
     * ISO 8601 timestamp in UTC, from a PostgreSQL timestamp string.
     */
    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
