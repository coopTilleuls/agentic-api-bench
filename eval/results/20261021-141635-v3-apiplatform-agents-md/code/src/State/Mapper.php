<?php

namespace App\State;

use App\ApiResource\Author;
use App\ApiResource\Book;
use App\ApiResource\Purchase;

/**
 * Builds the API resources from database rows.
 */
final class Mapper
{
    /**
     * @param array<string, mixed> $row
     */
    public static function author(array $row): Author
    {
        return new Author(
            id: (int) $row['id'],
            name: $row['name'],
            publishedBooks: (int) $row['published_books'],
        );
    }

    /**
     * The author as seen from a book: the relationship only needs the author's own attributes.
     *
     * @param array<string, mixed> $row
     */
    public static function bookAuthor(array $row): Author
    {
        return new Author(
            id: (int) $row['author_id'],
            name: $row['author_name'],
            publishedBooks: (int) $row['author_published_books'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function book(array $row): Book
    {
        return new Book(
            id: (int) $row['id'],
            title: $row['title'],
            priceCents: (int) $row['price_cents'],
            publishedAt: $row['published_at'],
            stock: (int) $row['stock'],
            author: self::bookAuthor($row),
        );
    }

    /**
     * @param array<string, mixed>        $row
     * @param list<array<string, mixed>>  $lines the purchase lines of this purchase
     */
    public static function purchase(array $row, array $lines): Purchase
    {
        return new Purchase(
            id: (int) $row['id'],
            createdAt: self::timestamp($row['created_at']),
            totalCents: (int) $row['total_cents'],
            lines: array_map(static fn (array $line): array => [
                'bookId' => (int) $line['book_id'],
                'title' => $line['title'],
                'quantity' => (int) $line['quantity'],
                'unitPriceCents' => (int) $line['unit_price_cents'],
            ], $lines),
        );
    }

    /**
     * An ISO 8601 timestamp in UTC.
     */
    public static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
