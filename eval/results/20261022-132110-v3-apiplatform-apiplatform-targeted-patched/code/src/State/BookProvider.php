<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiException;
use App\Api\QueryParams;
use App\Entity\Book;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads books. Only published books are visible, except to PATCH which also reaches unpublished ones.
 */
final class BookProvider implements ProviderInterface
{
    private const SORT_COLUMNS = [
        'priceCents' => 'b.priceCents',
        'title' => 'b.title',
        'publishedAt' => 'b.publishedAt',
    ];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        /** @var Request $request */
        $request = $context['request'];
        QueryParams::includes($request, ['author']);

        if ($operation instanceof CollectionOperationInterface) {
            return $this->collection($request);
        }

        $book = $this->entityManager->find(Book::class, QueryParams::id($uriVariables['id'] ?? null));

        if (null === $book || (!$book->isPublished() && !$operation instanceof Patch)) {
            throw ApiException::status(404, 'Book not found.');
        }

        return $book;
    }

    private function collection(Request $request): TraversablePaginator
    {
        $page = QueryParams::page($request);
        $sort = QueryParams::sort($request, self::SORT_COLUMNS);

        $query = $this->entityManager->createQueryBuilder()
            ->select('b')
            ->from(Book::class, 'b')
            ->where('b.published = true');
        $this->applyFilters($query, $request);

        $query->select('COUNT(b.id)');
        $total = (int) $query->getQuery()->getSingleScalarResult();

        $query->select('b');
        foreach ($sort as $column => $direction) {
            $query->addOrderBy($column, $direction);
        }
        $query->addOrderBy('b.id', 'ASC')
            ->setFirstResult(($page['number'] - 1) * $page['size'])
            ->setMaxResults($page['size']);

        return new TraversablePaginator(
            new \ArrayIterator($query->getQuery()->getResult()),
            $page['number'],
            $page['size'],
            $total,
        );
    }

    private function applyFilters(QueryBuilder $query, Request $request): void
    {
        $title = QueryParams::text($request, 'title');
        if (null !== $title) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($title));
            $query->andWhere("LOWER(b.title) LIKE :title ESCAPE '!'")->setParameter('title', '%'.$escaped.'%');
        }

        $author = QueryParams::text($request, 'author');
        if (null !== $author) {
            $query->join('b.author', 'a')->andWhere('a.name = :author')->setParameter('author', $author);
        }

        $minPrice = QueryParams::int($request, 'minPrice');
        if (null !== $minPrice) {
            $query->andWhere('b.priceCents >= :minPrice')->setParameter('minPrice', $minPrice);
        }

        $maxPrice = QueryParams::int($request, 'maxPrice');
        if (null !== $maxPrice) {
            $query->andWhere('b.priceCents <= :maxPrice')->setParameter('maxPrice', $maxPrice);
        }

        $publishedAfter = QueryParams::date($request, 'publishedAfter');
        if (null !== $publishedAfter) {
            $query->andWhere('b.publishedAt >= :publishedAfter')->setParameter('publishedAfter', $publishedAfter, Types::DATE_IMMUTABLE);
        }

        $publishedBefore = QueryParams::date($request, 'publishedBefore');
        if (null !== $publishedBefore) {
            $query->andWhere('b.publishedAt <= :publishedBefore')->setParameter('publishedBefore', $publishedBefore, Types::DATE_IMMUTABLE);
        }

        $inStock = QueryParams::bool($request, 'inStock');
        if (null !== $inStock) {
            $query->andWhere($inStock ? 'b.stock > 0' : 'b.stock = 0');
        }
    }
}
