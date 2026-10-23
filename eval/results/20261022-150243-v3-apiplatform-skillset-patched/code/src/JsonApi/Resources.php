<?php

namespace App\JsonApi;

/**
 * Builds JSON:API resource objects from database rows.
 */
final class Resources
{
    /**
     * @param array<string, mixed> $row columns id, title, price_cents, published_at, stock, author_id
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
                'publishedAt' => $row['published_at'],
                'stock' => (int) $row['stock'],
            ],
            'relationships' => [
                'author' => ['data' => ['type' => 'authors', 'id' => (string) $row['author_id']]],
            ],
            'links' => ['self' => '/books/'.$id],
        ];
    }

    /**
     * @param array<string, mixed>        $row            columns id, name, published_books
     * @param list<array<string, mixed>>|null $books      published book rows, when the books relationship is returned
     *
     * @return array<string, mixed>
     */
    public static function author(array $row, ?array $books = null): array
    {
        $id = (string) $row['id'];
        $resource = [
            'type' => 'authors',
            'id' => $id,
            'attributes' => [
                'name' => $row['name'],
                'publishedBooks' => (int) $row['published_books'],
            ],
            'links' => ['self' => '/authors/'.$id],
        ];

        if (null !== $books) {
            $resource['relationships'] = [
                'books' => ['data' => array_map(static fn (array $book): array => ['type' => 'books', 'id' => (string) $book['id']], $books)],
            ];
        }

        return $resource;
    }

    /**
     * @param array<string, mixed>        $row   columns id, created_at, total_cents
     * @param list<array<string, mixed>> $lines columns book_id, title, quantity, unit_price_cents
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
                'createdAt' => self::timestamp((string) $row['created_at']),
                'totalCents' => (int) $row['total_cents'],
                'lines' => array_map(static fn (array $line): array => [
                    'bookId' => (int) $line['book_id'],
                    'title' => $line['title'],
                    'quantity' => (int) $line['quantity'],
                    'unitPriceCents' => (int) $line['unit_price_cents'],
                ], $lines),
            ],
            'links' => ['self' => '/purchases/'.$id],
        ];
    }

    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
