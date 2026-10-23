<?php

namespace App\JsonApi;

/**
 * Builds the JSON:API resource objects for books, authors and purchases.
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
     * Author with its published books, as answered by the authors endpoints.
     *
     * @param array<string, mixed>   $row   author row
     * @param list<array<string, mixed>> $books published books of the author
     *
     * @return array<string, mixed>
     */
    public static function author(array $row, array $books): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => [
                'name' => $row['name'],
                'publishedBooks' => count($books),
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

    /**
     * Author as included with a book.
     *
     * @param array<string, mixed> $row author row
     *
     * @return array<string, mixed>
     */
    public static function authorRef(array $row): array
    {
        $id = (string) $row['id'];

        return [
            'type' => 'authors',
            'id' => $id,
            'attributes' => ['name' => $row['name']],
            'links' => ['self' => "/authors/$id"],
        ];
    }

    /**
     * @param array<string, mixed>   $row   purchase row
     * @param list<array<string, mixed>> $lines purchase line rows with book titles
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
                'createdAt' => (new \DateTimeImmutable((string) $row['created_at']))
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
                'totalCents' => (int) $row['total_cents'],
                'lines' => array_map(
                    static fn (array $line): array => [
                        'bookId' => (int) $line['book_id'],
                        'title' => $line['title'],
                        'quantity' => (int) $line['quantity'],
                        'unitPriceCents' => (int) $line['unit_price_cents'],
                    ],
                    $lines,
                ),
            ],
            'links' => ['self' => "/purchases/$id"],
        ];
    }
}
