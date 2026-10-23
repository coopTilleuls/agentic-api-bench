<?php

namespace App\JsonApi;

/**
 * Turns database rows into JSON:API resource objects.
 */
final class Serializer
{
    /** @param array<string, mixed> $row book row with author_id */
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
     * @param array<string, mixed>      $row   author row with published_books
     * @param list<array<string, mixed>> $books published books of the author
     */
    public static function author(array $row, array $books): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => [
                'name' => $row['name'],
                'publishedBooks' => (int) $row['published_books'],
            ],
            'relationships' => [
                'books' => ['data' => array_map(
                    static fn (array $book): array => ['type' => 'books', 'id' => (string) $book['id']],
                    $books,
                )],
            ],
            'links' => ['self' => "/authors/$id"],
        ];
    }

    /** @param array<string, mixed> $row author row with id and name */
    public static function authorIncluded(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => ['name' => $row['name']],
            'links' => ['self' => "/authors/$id"],
        ];
    }

    /** @param array<string, mixed> $row purchase row: id, created_at, total_cents */
    public static function purchase(array $row, array $lines): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'purchases',
            'id' => $id,
            'attributes' => [
                'createdAt' => self::timestamp((string) $row['created_at']),
                'totalCents' => (int) $row['total_cents'],
                'lines' => $lines,
            ],
            'links' => ['self' => "/purchases/$id"],
        ];
    }

    /** @param array<string, mixed> $row purchase line row with book title */
    public static function line(array $row): array
    {
        return [
            'bookId' => (int) $row['book_id'],
            'title' => $row['title'],
            'quantity' => (int) $row['quantity'],
            'unitPriceCents' => (int) $row['unit_price_cents'],
        ];
    }

    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
