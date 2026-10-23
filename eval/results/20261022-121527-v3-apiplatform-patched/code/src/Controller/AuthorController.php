<?php

namespace App\Controller;

use App\JsonApi\ApiException;
use App\JsonApi\JsonApi;
use App\JsonApi\Serializer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthorController
{
    private const SELECT = 'a.id, a.name, (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published = true) AS published_books';

    private const SORT_FIELDS = [
        'name' => 'a.name COLLATE "C"',
        'publishedBooks' => 'published_books',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/authors', name: 'authors_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $includes = JsonApi::includes($request, ['books']);
        $order = JsonApi::sortOrder($request, self::SORT_FIELDS);
        $order[] = 'a.id ASC';

        $page = JsonApi::fetchPage($this->db, $request, self::SELECT, 'FROM author a', [], implode(', ', $order));

        $books = $this->publishedBooks(array_column($page['rows'], 'id'));
        $booksByAuthor = [];
        foreach ($books as $book) {
            $booksByAuthor[(int) $book['author_id']][] = $book;
        }

        $data = array_map(
            static fn (array $author): array => Serializer::author($author, $booksByAuthor[(int) $author['id']] ?? []),
            $page['rows'],
        );
        $included = in_array('books', $includes, true) ? array_map(Serializer::book(...), $books) : [];

        return JsonApi::listDocument($request, $page, $data, $included);
    }

    #[Route('/authors/{id}', name: 'authors_show', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $includes = JsonApi::includes($request, ['books']);

        $authorId = JsonApi::id($id);
        $author = null === $authorId ? false : $this->db->fetchAssociative(
            'SELECT '.self::SELECT.' FROM author a WHERE a.id = :id',
            ['id' => $authorId],
        );
        if (false === $author) {
            throw ApiException::of(404, "Author $id not found.");
        }

        $books = $this->publishedBooks([$author['id']]);

        $body = ['data' => Serializer::author($author, $books)];
        if (in_array('books', $includes, true) && $books) {
            $body['included'] = array_map(Serializer::book(...), $books);
        }

        return JsonApi::document($body);
    }

    /**
     * Published books of the given authors, in id order.
     *
     * @param list<int|string> $authorIds
     *
     * @return list<array<string, mixed>>
     */
    private function publishedBooks(array $authorIds): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, b.author_id, b.price_cents, b.published_at, b.stock
             FROM book b
             WHERE b.published = true AND b.author_id IN ('.JsonApi::inList($authorIds).')
             ORDER BY b.id',
        );
    }
}
