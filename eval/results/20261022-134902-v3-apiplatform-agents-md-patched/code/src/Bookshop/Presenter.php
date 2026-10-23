<?php

namespace App\Bookshop;

use App\JsonApi\Document;
use Symfony\Component\HttpFoundation\Request;

/**
 * Turns database rows into JSON:API resource objects.
 */
final class Presenter
{
    /**
     * @param array<string, mixed> $row
     * @param list<int> $bookIds published books of the author
     * @return array<string, mixed>
     */
    public static function author(Request $request, array $row, array $bookIds): array
    {
        return Document::resource(
            $request,
            'authors',
            (int) $row['id'],
            [
                'name' => $row['name'] ?? null,
                'publishedBooks' => count($bookIds),
            ],
            ['books' => Document::toMany('books', $bookIds)],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function authorIncluded(Request $request, array $row): array
    {
        return Document::resource($request, 'authors', (int) $row['id'], ['name' => $row['name'] ?? null]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function book(Request $request, array $row): array
    {
        return Document::resource(
            $request,
            'books',
            (int) $row['id'],
            [
                'title' => $row['title'],
                'priceCents' => (int) $row['price_cents'],
                'publishedAt' => $row['published_at'] ?? null,
                'stock' => (int) $row['stock'],
            ],
            ['author' => Document::toOne('authors', (int) $row['author_id'])],
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    public static function purchase(Request $request, array $row, array $lines): array
    {
        return Document::resource(
            $request,
            'purchases',
            (int) $row['id'],
            [
                'createdAt' => self::timestamp($row['created_at']),
                'totalCents' => (int) $row['total_cents'],
                'lines' => array_map(static fn (array $line) => [
                    'bookId' => (int) $line['book_id'],
                    'title' => $line['title'],
                    'quantity' => (int) $line['quantity'],
                    'unitPriceCents' => (int) $line['unit_price_cents'],
                ], $lines),
            ],
        );
    }

    /**
     * ISO 8601 timestamp in UTC.
     */
    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
