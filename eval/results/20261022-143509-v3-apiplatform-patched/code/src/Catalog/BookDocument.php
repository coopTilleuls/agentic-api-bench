<?php

namespace App\Catalog;

final class BookDocument
{
    /** Sort names accepted by GET /books, with their SQL column. */
    public const SORT_FIELDS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function resource(array $row, string $baseUrl): array
    {
        return [
            'type' => 'books',
            'id' => (string) $row['id'],
            'attributes' => [
                'title' => $row['title'],
                'priceCents' => (int) $row['price_cents'],
                'publishedAt' => $row['published_at'],
                'stock' => (int) $row['stock'],
            ],
            'relationships' => [
                'author' => ['data' => AuthorDocument::linkage((int) $row['author_id'])],
            ],
            'links' => ['self' => $baseUrl.'/books/'.$row['id']],
        ];
    }
}
