<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * JSON:API resource objects of the books, authors and purchases.
 */
final class Resources
{
    /** @param array<string, mixed> $row a row of the book repository */
    public static function book(array $row, Request $request): array
    {
        $id = (int) $row['id'];

        return [
            'type' => 'books',
            'id' => (string) $id,
            'attributes' => [
                'title' => $row['title'],
                'priceCents' => (int) $row['price_cents'],
                'publishedAt' => $row['published_at'],
                'stock' => (int) $row['stock'],
            ],
            'relationships' => [
                'author' => ['data' => ['type' => 'authors', 'id' => (string) $row['author_id']]],
            ],
            'links' => ['self' => JsonApi::absolute($request, '/books/'.$id)],
        ];
    }

    /**
     * @param array<string, mixed>        $row   a row of the author repository
     * @param list<array<string, mixed>>|null $books the published books of the author, when they are a relationship
     */
    public static function author(array $row, Request $request, ?array $books = null): array
    {
        $id = (int) $row['id'];
        $resource = [
            'type' => 'authors',
            'id' => (string) $id,
            'attributes' => [
                'name' => $row['name'],
                'publishedBooks' => (int) $row['published_books'],
            ],
            'links' => ['self' => JsonApi::absolute($request, '/authors/'.$id)],
        ];

        if (null !== $books) {
            $resource['relationships'] = [
                'books' => [
                    'data' => array_map(static fn (array $book): array => ['type' => 'books', 'id' => (string) $book['id']], $books),
                ],
            ];
        }

        return $resource;
    }

    /**
     * @param array<string, mixed>              $row   a purchase row: id, created_at, total_cents
     * @param list<array<string, mixed>>        $lines the lines: book_id, title, quantity, unit_price_cents
     */
    public static function purchase(array $row, array $lines, Request $request): array
    {
        $id = (int) $row['id'];

        return [
            'type' => 'purchases',
            'id' => (string) $id,
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
            'links' => ['self' => JsonApi::absolute($request, '/purchases/'.$id)],
        ];
    }

    /** An ISO 8601 timestamp in UTC. */
    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
