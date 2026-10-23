<?php

namespace App\State;

use App\ApiResource\AuthorResource;
use App\ApiResource\BookResource;
use App\ApiResource\PurchaseResource;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Purchase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps entities to the API resources, loading the related published books in one query.
 */
final class ResourceMapper
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<Book> $books
     *
     * @return list<BookResource>
     */
    public function books(array $books): array
    {
        $authors = [];

        foreach ($books as $book) {
            $authors[$book->getAuthor()->getId()] = $book->getAuthor();
        }

        $authorResources = $this->authors(array_values($authors));

        return array_map(
            fn (Book $book): BookResource => $this->book($book, $authorResources[$book->getAuthor()->getId()]),
            $books,
        );
    }

    /**
     * @param list<Author> $authors
     *
     * @return array<int, AuthorResource> keyed by id
     */
    public function authors(array $authors): array
    {
        $resources = [];

        foreach ($authors as $author) {
            $resource = new AuthorResource();
            $resource->id = $author->getId();
            $resource->name = $author->getName();
            $resources[$resource->id] = $resource;
        }

        if ([] === $resources) {
            return [];
        }

        $books = $this->entityManager->createQuery(
            'SELECT b, a FROM App\Entity\Book b JOIN b.author a WHERE b.published = true AND a.id IN (:ids) ORDER BY b.id ASC',
        )->setParameter('ids', array_keys($resources))->getResult();

        foreach ($books as $book) {
            $author = $resources[$book->getAuthor()->getId()];
            $author->books[] = $this->book($book, $author);
        }

        foreach ($resources as $resource) {
            $resource->publishedBooks = \count($resource->books);
        }

        return $resources;
    }

    public function book(Book $book, AuthorResource $author): BookResource
    {
        $resource = new BookResource();
        $resource->id = $book->getId();
        $resource->title = $book->getTitle();
        $resource->priceCents = $book->getPriceCents();
        $resource->publishedAt = $book->getPublishedAt()?->format('Y-m-d');
        $resource->stock = $book->getStock();
        $resource->author = $author;

        return $resource;
    }

    public function purchase(Purchase $purchase): PurchaseResource
    {
        $resource = new PurchaseResource();
        $resource->id = $purchase->getId();
        $resource->createdAt = $purchase->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $resource->totalCents = $purchase->getTotalCents();
        $resource->lines = [];

        foreach ($purchase->getLines() as $line) {
            $resource->lines[] = [
                'bookId' => $line->getBook()->getId(),
                'title' => $line->getBook()->getTitle(),
                'quantity' => $line->getQuantity(),
                'unitPriceCents' => $line->getUnitPriceCents(),
            ];
        }

        return $resource;
    }
}
