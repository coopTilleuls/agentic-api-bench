<?php

namespace App\Bookshop;

/**
 * JSON:API resource objects of the bookshop resources.
 */
final class Presenter
{
    /**
     * @param array<string, mixed>  $row
     * @param list<string>|null     $bookIds the published books of the author, or null to omit the relationship
     *
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
     * @param array<string, mixed>        $row
     * @param list<array<string, mixed>>  $lines
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
                'createdAt' => (new \DateTimeImmutable($row['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM),
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
}
