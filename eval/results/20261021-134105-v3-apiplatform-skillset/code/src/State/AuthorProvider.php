<?php

namespace App\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AuthorResource;
use App\ApiResource\BookResource;
use App\Entity\Author;
use App\Entity\Book;
use App\JsonApi\JsonApiCollection;
use App\JsonApi\Identifier;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProviderInterface<AuthorResource|JsonApiCollection>
 */
final class AuthorProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QueryParser $query,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AuthorResource|JsonApiCollection
    {
        if ($operation instanceof GetCollection) {
            return $this->collection();
        }
        if (!$operation instanceof Get) {
            throw JsonApiException::error(405, 'Method not allowed.');
        }

        $author = $this->find($uriVariables['id'] ?? null);
        if (null === $author) {
            throw JsonApiException::error(404, 'Author not found.');
        }

        $this->query->include(['books']);
        $books = $this->em->getRepository(Book::class)->findBy(['author' => $author, 'published' => true], ['id' => 'ASC']);
        $resource = AuthorResource::fromEntity($author, \count($books));
        $resource->books = array_map(BookResource::fromEntity(...), $books);

        return $resource;
    }

    private function collection(): JsonApiCollection
    {
        $this->query->include(['books']);
        $this->query->sort([]);
        $page = $this->query->pagination();

        $rows = $this->em->createQueryBuilder()
            ->select('a', 'COUNT(b.id) AS publishedBooks')
            ->from(Author::class, 'a')
            ->leftJoin(Book::class, 'b', 'WITH', 'b.author = a AND b.published = true')
            ->groupBy('a.id')
            ->orderBy('a.id', 'ASC')
            ->setFirstResult(($page['number'] - 1) * $page['size'])
            ->setMaxResults($page['size'])
            ->getQuery()
            ->getResult();

        $total = (int) $this->em->createQuery('SELECT COUNT(a.id) FROM '.Author::class.' a')->getSingleScalarResult();

        $items = [];
        foreach ($rows as $row) {
            $items[] = AuthorResource::fromEntity($row[0], (int) $row['publishedBooks']);
        }

        $this->includeBooks($items);

        return new JsonApiCollection($items, $total, $page['number'], $page['size']);
    }

    /**
     * Loads the published books of the authors of a page, for ?include=books.
     *
     * @param list<AuthorResource> $authors
     */
    private function includeBooks(array $authors): void
    {
        if ([] === $authors || !\in_array('books', $this->query->include(['books']), true)) {
            return;
        }

        $byAuthor = [];
        foreach ($authors as $author) {
            $byAuthor[$author->id] = [];
        }

        $books = $this->em->createQueryBuilder()
            ->select('b', 'a')
            ->from(Book::class, 'b')
            ->join('b.author', 'a')
            ->where('a.id IN (:ids)')
            ->andWhere('b.published = true')
            ->setParameter('ids', array_keys($byAuthor))
            ->orderBy('b.id', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($books as $book) {
            $byAuthor[$book->getAuthor()->getId()][] = BookResource::fromEntity($book);
        }
        foreach ($authors as $author) {
            $author->books = $byAuthor[$author->id];
        }
    }

    private function find(mixed $id): ?Author
    {
        $id = Identifier::fromPath($id);

        return null === $id ? null : $this->em->find(Author::class, $id);
    }
}
