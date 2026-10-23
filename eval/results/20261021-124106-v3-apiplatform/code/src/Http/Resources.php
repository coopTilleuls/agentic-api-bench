<?php

namespace App\Http;

/**
 * Turns database rows into JSON:API resource objects.
 */
final class Resources
{
    /** @param array<string, mixed> $row */
    public static function book(array $row): array
    {
        return JsonApi::resource('books', (string) $row['id'], [
            'title' => $row['title'],
            'priceCents' => (int) $row['price_cents'],
            'publishedAt' => $row['published_at'],
            'stock' => (int) $row['stock'],
        ], [
            'author' => ['data' => ['type' => 'authors', 'id' => (string) $row['author_id']]],
        ]);
    }

    /**
     * @param array<string, mixed>            $row
     * @param list<array{type: string, id: string}>|null $books linkage of the author's books, null to omit the relationship
     */
    public static function author(array $row, ?array $books = null): array
    {
        return JsonApi::resource(
            'authors',
            (string) $row['id'],
            ['name' => $row['name'], 'publishedBooks' => (int) $row['published_books']],
            null === $books ? null : ['books' => ['data' => $books]],
        );
    }

    /** @param array<string, mixed> $row */
    public static function authorReference(array $row): array
    {
        return JsonApi::resource('authors', (string) $row['id'], ['name' => $row['name']]);
    }

    /**
     * @param array<string, mixed>        $row
     * @param list<array<string, mixed>> $lines rows of purchase_line joined with the book title
     */
    public static function purchase(array $row, array $lines): array
    {
        return JsonApi::resource('purchases', (string) $row['id'], [
            'createdAt' => self::timestamp($row['created_at']),
            'totalCents' => (int) $row['total_cents'],
            'lines' => array_map(static fn (array $line): array => [
                'bookId' => (int) $line['book_id'],
                'title' => $line['title'],
                'quantity' => (int) $line['quantity'],
                'unitPriceCents' => (int) $line['unit_price_cents'],
            ], $lines),
        ]);
    }

    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
