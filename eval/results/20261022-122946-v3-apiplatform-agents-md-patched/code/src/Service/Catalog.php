<?php

namespace App\Service;

use App\ApiResource\Author;
use App\ApiResource\Book;
use App\ApiResource\Purchase;
use App\Http\ListParams;
use App\Pagination\Page;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Reads the bookshop tables and maps rows to the API resources.
 *
 * Unpublished books are invisible to every read made here unless the caller asks for them explicitly.
 */
final class Catalog
{
    private const BOOK_SORT_COLUMNS = [
        'priceCents' => 'b.price_cents',
        'title' => 'b.title',
        'publishedAt' => 'b.published_at',
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    public function findAuthor(int $id): ?Author
    {
        $page = $this->authorRows('WHERE a.id = :id', ['id' => $id], 1, 0, false);

        return $page[0] ?? null;
    }

    public function findAuthorsPage(int $number, int $size): Page
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM author');
        $authors = $this->authorRows('', [], $size, ($number - 1) * $size, true);

        return new Page($authors, $number, $size, $total);
    }

    /**
     * @param list<Author> $authors
     */
    public function attachPublishedBooks(array $authors): void
    {
        if ([] === $authors) {
            return;
        }

        $ids = array_map(static fn (Author $author) => $author->id, $authors);
        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id
             FROM book b
             WHERE b.published = true AND b.author_id IN (:ids)
             ORDER BY b.id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $byAuthor = [];
        foreach ($rows as $row) {
            $byAuthor[(int) $row['author_id']][] = $this->bookFromRow($row, null);
        }
        foreach ($authors as $author) {
            $author->books = $byAuthor[$author->id] ?? [];
            foreach ($author->books as $book) {
                $book->author = $author;
            }
        }
    }

    public function findBook(int $id, bool $includeUnpublished = false): ?Book
    {
        $rows = $this->bookRows(' AND b.id = :id', ['id' => $id], 1, 0, [], !$includeUnpublished);

        return $rows[0] ?? null;
    }

    public function findBooksPage(ListParams $params): Page
    {
        [$where, $bindings] = $this->bookFilters($params);
        $total = (int) $this->db->fetchOne(
            'SELECT COUNT(*) FROM book b JOIN author a ON a.id = b.author_id WHERE b.published = true'.$where,
            $bindings,
        );
        $books = $this->bookRows($where, $bindings, $params->size, ($params->number - 1) * $params->size, $params->sort, true);

        return new Page($books, $params->number, $params->size, $total);
    }

    /**
     * Books in the same order as the list, with their author attached (linkage and included data).
     *
     * @param array<string, mixed> $bindings
     * @param array<string, 'asc'|'desc'> $sort
     *
     * @return list<Book>
     */
    private function bookRows(string $where, array $bindings, int $limit, int $offset, array $sort, bool $publishedOnly): array
    {
        $order = [];
        foreach ($sort as $field => $direction) {
            $order[] = self::BOOK_SORT_COLUMNS[$field].' '.strtoupper($direction);
        }
        $order[] = 'b.id ASC';

        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id, b.title, b.price_cents, b.published_at, b.stock, b.author_id,
                    a.name AS author_name,
                    (SELECT COUNT(*) FROM book pb WHERE pb.author_id = a.id AND pb.published = true) AS author_published_books
             FROM book b
             JOIN author a ON a.id = b.author_id
             WHERE '.($publishedOnly ? 'b.published = true' : 'TRUE').$where.'
             ORDER BY '.implode(', ', $order).'
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        $books = array_map(fn (array $row) => $this->bookFromRow($row, $this->authorFromRow($row)), $rows);
        $authors = [];
        foreach ($books as $book) {
            $authors[$book->author->id] = $book->author;
        }
        $this->attachPublishedBooks(array_values($authors));

        return $books;
    }

    /**
     * Only the purchases of the given customer are ever visible.
     */
    public function findPurchase(int $customerId, int $id): ?Purchase
    {
        $rows = $this->purchaseRows('WHERE p.customer_id = :customer AND p.id = :id', ['customer' => $customerId, 'id' => $id], 1, 0);

        return $rows[0] ?? null;
    }

    public function findPurchasesPage(int $customerId, ListParams $params): Page
    {
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = :customer', ['customer' => $customerId]);
        $purchases = $this->purchaseRows(
            'WHERE p.customer_id = :customer',
            ['customer' => $customerId],
            $params->size,
            ($params->number - 1) * $params->size,
        );

        return new Page($purchases, $params->number, $params->size, $total);
    }

    /**
     * @param array<string, mixed> $bindings
     *
     * @return list<Purchase>
     */
    private function purchaseRows(string $where, array $bindings, int $limit, int $offset): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT p.id, p.created_at, p.total_cents
             FROM purchase p '.$where.'
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :limit OFFSET :offset',
            $bindings + ['limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );
        if ([] === $rows) {
            return [];
        }

        $lines = [];
        $lineRows = $this->db->fetchAllAssociative(
            'SELECT pl.purchase_id, pl.book_id, b.title, pl.quantity, pl.unit_price_cents
             FROM purchase_line pl
             JOIN book b ON b.id = pl.book_id
             WHERE pl.purchase_id IN (:ids)
             ORDER BY pl.id',
            ['ids' => array_column($rows, 'id')],
            ['ids' => ArrayParameterType::INTEGER],
        );
        foreach ($lineRows as $line) {
            $lines[(int) $line['purchase_id']][] = [
                'bookId' => (int) $line['book_id'],
                'title' => (string) $line['title'],
                'quantity' => (int) $line['quantity'],
                'unitPriceCents' => (int) $line['unit_price_cents'],
            ];
        }

        return array_map(function (array $row) use ($lines) {
            $purchase = new Purchase();
            $purchase->id = (int) $row['id'];
            $purchase->createdAt = (new \DateTimeImmutable($row['created_at']))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
            $purchase->totalCents = (int) $row['total_cents'];
            $purchase->lines = $lines[$purchase->id] ?? [];

            return $purchase;
        }, $rows);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function bookFilters(ListParams $params): array
    {
        $where = '';
        $bindings = [];

        if (null !== $title = $params->raw('title')) {
            $where .= " AND b.title ILIKE :title";
            $bindings['title'] = '%'.addcslashes($title, '\\%_').'%';
        }
        if (null !== $author = $params->raw('author')) {
            $where .= ' AND a.name = :author';
            $bindings['author'] = $author;
        }
        if (null !== $min = $params->integer('minPrice')) {
            $where .= ' AND b.price_cents >= :minPrice';
            $bindings['minPrice'] = $min;
        }
        if (null !== $max = $params->integer('maxPrice')) {
            $where .= ' AND b.price_cents <= :maxPrice';
            $bindings['maxPrice'] = $max;
        }
        if (null !== $after = $params->date('publishedAfter')) {
            $where .= ' AND b.published_at >= :publishedAfter';
            $bindings['publishedAfter'] = $after;
        }
        if (null !== $before = $params->date('publishedBefore')) {
            $where .= ' AND b.published_at <= :publishedBefore';
            $bindings['publishedBefore'] = $before;
        }
        if (null !== $inStock = $params->boolean('inStock')) {
            $where .= $inStock ? ' AND b.stock > 0' : ' AND b.stock = 0';
        }

        return [$where, $bindings];
    }

    /**
     * @param array<string, mixed> $bindings
     *
     * @return list<Author>
     */
    private function authorRows(string $where, array $bindings, int $limit, int $offset, bool $paged): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.name,
                    (SELECT COUNT(*) FROM book b WHERE b.author_id = a.id AND b.published = true) AS published_books
             FROM author a '.$where.'
             ORDER BY a.id ASC'.($paged ? ' LIMIT :limit OFFSET :offset' : ''),
            $bindings + ($paged ? ['limit' => $limit, 'offset' => $offset] : []),
            $paged ? ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER] : [],
        );

        return array_map(fn (array $row) => $this->authorFromRow($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function authorFromRow(array $row): Author
    {
        $author = new Author();
        $author->id = (int) ($row['author_id'] ?? $row['id']);
        $author->name = $row['author_name'] ?? $row['name'] ?? null;
        $author->publishedBooks = (int) ($row['author_published_books'] ?? $row['published_books'] ?? 0);

        return $author;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function bookFromRow(array $row, ?Author $author): Book
    {
        $book = new Book();
        $book->id = (int) $row['id'];
        $book->title = (string) $row['title'];
        $book->priceCents = (int) $row['price_cents'];
        $book->publishedAt = $row['published_at'];
        $book->stock = (int) $row['stock'];
        $book->author = $author;

        return $book;
    }
}
