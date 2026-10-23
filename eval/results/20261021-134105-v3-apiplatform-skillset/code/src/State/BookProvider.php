<?php

namespace App\State;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\BookResource;
use App\Entity\Book;
use App\JsonApi\JsonApiCollection;
use App\JsonApi\Identifier;
use App\JsonApi\JsonApiException;
use App\JsonApi\QueryParser;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * @implements ProviderInterface<BookResource|JsonApiCollection>
 */
final class BookProvider implements ProviderInterface
{
    /** Sortable fields, by JSON:API name. */
    private const SORT_FIELDS = [
        'priceCents' => 'b.priceCents',
        'title' => 'b.title',
        'publishedAt' => 'b.publishedAt',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly QueryParser $query,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): BookResource|JsonApiCollection
    {
        if ($operation instanceof GetCollection) {
            return $this->collection();
        }

        $book = $this->find($uriVariables['id'] ?? null);
        // Unpublished books are only visible to PATCH.
        if (null === $book || (!$operation instanceof Patch && !$book->isPublished())) {
            throw JsonApiException::error(404, 'Book not found.');
        }
        if ($operation instanceof Get) {
            $this->query->include(['author']);
        }

        return BookResource::fromEntity($book);
    }

    private function collection(): JsonApiCollection
    {
        $this->query->include(['author']);
        $sort = $this->query->sort(array_keys(self::SORT_FIELDS));
        $page = $this->query->pagination();

        $qb = $this->em->createQueryBuilder()
            ->select('b', 'a')
            ->from(Book::class, 'b')
            ->join('b.author', 'a')
            ->where('b.published = :published')
            ->setParameter('published', true);
        $this->applyFilters($qb);

        $total = (int) (clone $qb)->select('COUNT(b.id)')->getQuery()->getSingleScalarResult();

        foreach ($sort as [$field, $direction]) {
            $qb->addOrderBy(self::SORT_FIELDS[$field], $direction);
        }
        $books = $qb->addOrderBy('b.id', 'ASC')
            ->setFirstResult(($page['number'] - 1) * $page['size'])
            ->setMaxResults($page['size'])
            ->getQuery()
            ->getResult();

        return new JsonApiCollection(array_map(BookResource::fromEntity(...), $books), $total, $page['number'], $page['size']);
    }

    private function applyFilters(QueryBuilder $qb): void
    {
        $title = $this->query->text('title');
        if (null !== $title && '' !== $title) {
            $qb->andWhere('LOWER(b.title) LIKE :title')
                ->setParameter('title', '%'.addcslashes(mb_strtolower($title), '%_\\').'%');
        }

        $author = $this->query->text('author');
        if (null !== $author) {
            $qb->andWhere('a.name = :author')->setParameter('author', $author);
        }

        $minPrice = $this->query->integerFilter('minPrice');
        if (null !== $minPrice) {
            $qb->andWhere('b.priceCents >= :minPrice')->setParameter('minPrice', $minPrice);
        }

        $maxPrice = $this->query->integerFilter('maxPrice');
        if (null !== $maxPrice) {
            $qb->andWhere('b.priceCents <= :maxPrice')->setParameter('maxPrice', $maxPrice);
        }

        $publishedAfter = $this->query->dateFilter('publishedAfter');
        if (null !== $publishedAfter) {
            $qb->andWhere('b.publishedAt >= :publishedAfter')->setParameter('publishedAfter', $publishedAfter, Types::DATE_IMMUTABLE);
        }

        $publishedBefore = $this->query->dateFilter('publishedBefore');
        if (null !== $publishedBefore) {
            $qb->andWhere('b.publishedAt <= :publishedBefore')->setParameter('publishedBefore', $publishedBefore, Types::DATE_IMMUTABLE);
        }

        $inStock = $this->query->booleanFilter('inStock');
        if (null !== $inStock) {
            $qb->andWhere($inStock ? 'b.stock > 0' : 'b.stock = 0');
        }
    }

    private function find(mixed $id): ?Book
    {
        $id = Identifier::fromPath($id);

        return null === $id ? null : $this->em->find(Book::class, $id);
    }
}
