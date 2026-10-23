<?php

namespace App\JsonApi;

/**
 * Turns database rows into JSON:API resource objects.
 */
final class Resources
{
    /**
     * @param array<string, mixed> $row book row
     *
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
                'publishedAt' => $row['published_at'] ?? null,
                'stock' => (int) $row['stock'],
            ],
            'relationships' => [
                'author' => ['data' => self::identifier('authors', (string) $row['author_id'])],
            ],
            'links' => ['self' => "/books/$id"],
        ];
    }

    /**
     * @param array<string, mixed> $row     author row
     * @param list<int|string>     $bookIds published book ids, in id order
     *
     * @return array<string, mixed>
     */
    public static function author(array $row, array $bookIds): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => [
                'name' => $row['name'] ?? null,
                'publishedBooks' => \count($bookIds),
            ],
            'relationships' => [
                'books' => ['data' => array_map(static fn ($bookId) => self::identifier('books', (string) $bookId), $bookIds)],
            ],
            'links' => ['self' => "/authors/$id"],
        ];
    }

    /**
     * Author as it appears in `included`.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function authorIncluded(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => ['name' => $row['name'] ?? null],
            'links' => ['self' => "/authors/$id"],
        ];
    }

    /**
     * @param array<string, mixed> $row   purchase row
     * @param list<array<string, mixed>> $lines purchase lines (book_id, title, quantity, unit_price_cents)
     *
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
                'lines' => array_map(static fn (array $line) => [
                    'bookId' => (int) $line['book_id'],
                    'title' => $line['title'],
                    'quantity' => (int) $line['quantity'],
                    'unitPriceCents' => (int) $line['unit_price_cents'],
                ], $lines),
            ],
            'links' => ['self' => "/purchases/$id"],
        ];
    }

    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array{type: string, id: string}
     */
    public static function identifier(string $type, string $id): array
    {
        return ['type' => $type, 'id' => $id];
    }
}
