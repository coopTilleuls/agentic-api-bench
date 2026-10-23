<?php

namespace App\Api;

use App\ApiResource\AuthorResource;
use App\ApiResource\BookResource;
use App\ApiResource\PurchaseResource;
use App\Entity\Author;
use App\Entity\Book;
use App\Entity\Purchase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps Doctrine entities to the resource objects that API Platform serializes.
 *
 * An author's books point back at that same author object, so the mapping does not recurse.
 */
final class ResourceMapper
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function book(Book $book, ?AuthorResource $author = null): BookResource
    {
        $resource = new BookResource();
        $resource->id = (string) $book->getId();
        $resource->title = $book->getTitle();
        $resource->priceCents = $book->getPriceCents();
        $resource->publishedAt = $book->getPublishedAt()?->format('Y-m-d');
        $resource->stock = $book->getStock();
        $resource->author = $author ?? $this->author($book->getAuthor());

        return $resource;
    }

    public function author(Author $author): AuthorResource
    {
        $resource = new AuthorResource();
        $resource->id = (string) $author->getId();
        $resource->name = $author->getName();

        $books = $this->em->createQueryBuilder()
            ->select('b')
            ->from(Book::class, 'b')
            ->where('b.author = :author AND b.published = true')
            ->orderBy('b.id', 'ASC')
            ->setParameter('author', $author)
            ->getQuery()
            ->getResult();

        $resource->publishedBooks = \count($books);
        $resource->books = array_map(fn (Book $book) => $this->book($book, $resource), $books);

        return $resource;
    }

    public function purchase(Purchase $purchase): PurchaseResource
    {
        $resource = new PurchaseResource();
        $resource->id = (string) $purchase->getId();
        $resource->createdAt = $purchase->getCreatedAt()->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
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
