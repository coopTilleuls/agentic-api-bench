<?php

namespace App\Catalog;

final class AuthorDocument
{
    /**
     * @param array<string, mixed>                 $row
     * @param list<array<string, mixed>>|null      $books when given, the books relationship is emitted
     *
     * @return array<string, mixed>
     */
    public static function resource(array $row, string $baseUrl, ?array $books = null): array
    {
        $resource = [
            'type' => 'authors',
            'id' => (string) $row['id'],
            'attributes' => [
                'name' => $row['name'],
                'publishedBooks' => (int) ($row['published_books'] ?? 0),
            ],
            'links' => ['self' => $baseUrl.'/authors/'.$row['id']],
        ];
        if (null !== $books) {
            $resource['relationships'] = [
                'books' => [
                    'data' => array_map(
                        static fn (array $book): array => ['type' => 'books', 'id' => (string) $book['id']],
                        $books,
                    ),
                ],
            ];
        }

        return $resource;
    }

    /**
     * An author as an included resource: its name only.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function included(array $row, string $baseUrl): array
    {
        return [
            'type' => 'authors',
            'id' => (string) $row['id'],
            'attributes' => ['name' => $row['name']],
            'links' => ['self' => $baseUrl.'/authors/'.$row['id']],
        ];
    }

    /**
     * @return array{type: string, id: string}
     */
    public static function linkage(int $id): array
    {
        return ['type' => 'authors', 'id' => (string) $id];
    }
}
