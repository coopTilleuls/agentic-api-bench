<?php

namespace App\State;

use App\ApiResource\Author;
use App\ApiResource\Book;
use App\ApiResource\Purchase;
use App\Repository\BookshopRepository;

/**
 * Builds the API resource objects from the rows of the bookshop tables.
 */
final class BookshopMapper
{
    public function __construct(
        private readonly BookshopRepository $repository,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Book>
     */
    public function books(array $rows): array
    {
        $authorIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['author_id'], $rows)));
        $authors = $this->authorsByIds($authorIds);

        $books = [];
        foreach ($rows as $row) {
            $book = $this->book($row);
            $book->author = $authors[(int) $row['author_id']] ?? null;
            $books[] = $book;
        }

        return $books;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function book(array $row): Book
    {
        $book = new Book();
        $book->id = (int) $row['id'];
        $book->title = (string) $row['title'];
        $book->priceCents = (int) $row['price_cents'];
        $book->publishedAt = null === $row['published_at'] ? null : substr((string) $row['published_at'], 0, 10);
        $book->stock = (int) $row['stock'];

        return $book;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Author> the authors by id
     */
    public function authorsByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $authors = [];
        foreach ($this->authorsFromRows($this->repository->findAuthors($ids)) as $author) {
            $authors[$author->id] = $author;
        }

        return $authors;
    }

    /**
     * @param list<array<string, mixed>> $authorRows
     *
     * @return list<Author>
     */
    public function authorsFromRows(array $authorRows): array
    {
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $authorRows);

        $authors = [];
        foreach ($authorRows as $row) {
            $author = new Author();
            $author->id = (int) $row['id'];
            $author->name = null === $row['name'] ? null : (string) $row['name'];
            $author->publishedBooks = (int) $row['published_books'];
            $authors[$author->id] = $author;
        }

        foreach ($this->repository->findPublishedBooksOfAuthors($ids) as $row) {
            $book = $this->book($row);
            $book->author = $authors[(int) $row['author_id']];
            $authors[(int) $row['author_id']]->books[] = $book;
        }

        return array_values($authors);
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Purchase>
     */
    public function purchases(array $rows): array
    {
        $lineRows = $this->repository->findPurchaseLines(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $linesByPurchase = [];
        foreach ($lineRows as $line) {
            $linesByPurchase[(int) $line['purchase_id']][] = [
                'bookId' => (int) $line['book_id'],
                'title' => (string) $line['title'],
                'quantity' => (int) $line['quantity'],
                'unitPriceCents' => (int) $line['unit_price_cents'],
            ];
        }

        return array_map(
            fn (array $row): Purchase => $this->purchase($row, $linesByPurchase[(int) $row['id']] ?? []),
            $rows,
        );
    }

    /**
     * @param array<string, mixed>                                                            $row
     * @param list<array{bookId: int, title: string, quantity: int, unitPriceCents: int}> $lines
     */
    public function purchase(array $row, array $lines): Purchase
    {
        $purchase = new Purchase();
        $purchase->id = (int) $row['id'];
        $purchase->createdAt = (new \DateTimeImmutable((string) $row['created_at']))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $purchase->totalCents = (int) $row['total_cents'];
        $purchase->lines = $lines;

        return $purchase;
    }
}
