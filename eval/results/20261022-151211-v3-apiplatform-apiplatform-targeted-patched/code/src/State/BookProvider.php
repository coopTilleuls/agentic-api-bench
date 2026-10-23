<?php

namespace App\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\Api\ListQuery;
use App\Api\PageResult;
use App\Api\ResourceMapper;
use App\Entity\Book;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Published books only, except for PATCH which also reaches unpublished ones.
 */
final class BookProvider implements ProviderInterface
{
    private const SORT_FIELDS = [
        'id' => 'b.id',
        'priceCents' => 'b.priceCents',
        'title' => 'b.title',
        'publishedAt' => 'b.publishedAt',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ListQuery $query,
        private readonly ResourceMapper $mapper,
        private readonly RequestStack $requests,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requests->getCurrentRequest();

        if ($operation instanceof GetCollection) {
            return $this->collection($request);
        }

        $book = $this->em->find(Book::class, (int) $uriVariables['id']);
        if (null === $book) {
            return null;
        }
        if ($operation instanceof Patch) {
            return $this->mapper->book($book);
        }
        if (!$book->isPublished()) {
            return null;
        }

        $this->query->include($request, ['author']);

        return $this->mapper->book($book);
    }

    private function collection(Request $request): PageResult
    {
        $this->query->include($request, ['author']);
        $page = $this->query->page($request);
        $sorts = $this->query->sort($request, self::SORT_FIELDS);

        $qb = $this->em->createQueryBuilder()
            ->select('b')
            ->from(Book::class, 'b')
            ->innerJoin('b.author', 'a')
            ->where('b.published = true');

        if (null !== $title = $this->query->filter($request, 'title')) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($title));
            $qb->andWhere("LOWER(b.title) LIKE :title ESCAPE '!'")->setParameter('title', '%'.$escaped.'%');
        }
        if (null !== $author = $this->query->filter($request, 'author')) {
            $qb->andWhere('a.name = :author')->setParameter('author', $author);
        }
        if (null !== $minPrice = $this->query->integerFilter($request, 'minPrice')) {
            $qb->andWhere('b.priceCents >= :minPrice')->setParameter('minPrice', $minPrice);
        }
        if (null !== $maxPrice = $this->query->integerFilter($request, 'maxPrice')) {
            $qb->andWhere('b.priceCents <= :maxPrice')->setParameter('maxPrice', $maxPrice);
        }
        if (null !== $after = $this->query->dateFilter($request, 'publishedAfter')) {
            $qb->andWhere('b.publishedAt >= :publishedAfter')->setParameter('publishedAfter', $after, Types::DATE_IMMUTABLE);
        }
        if (null !== $before = $this->query->dateFilter($request, 'publishedBefore')) {
            $qb->andWhere('b.publishedAt <= :publishedBefore')->setParameter('publishedBefore', $before, Types::DATE_IMMUTABLE);
        }
        if (null !== $inStock = $this->query->booleanFilter($request, 'inStock')) {
            $qb->andWhere($inStock ? 'b.stock > 0' : 'b.stock = 0');
        }

        foreach ($sorts as [$column, $direction]) {
            $qb->addOrderBy($column, $direction);
        }
        $qb->addOrderBy('b.id', 'ASC');

        $total = (int) $this->countQuery($qb)->getSingleScalarResult();
        $books = $qb
            ->setFirstResult(($page['number'] - 1) * $page['size'])
            ->setMaxResults($page['size'])
            ->getQuery()
            ->getResult();

        return new PageResult(
            array_map(fn (Book $book) => $this->mapper->book($book), $books),
            $total,
            $page['number'],
            $page['size'],
        );
    }

    private function countQuery(QueryBuilder $qb): \Doctrine\ORM\Query
    {
        return (clone $qb)->select('COUNT(b.id)')->resetDQLPart('orderBy')->getQuery();
    }
}
