<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\Query\ListQuery;
use App\Entity\Book;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * Books: only published ones are visible, except to PATCH.
 *
 * @implements ProviderInterface<object>
 */
final class BookProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ListQuery $query,
        private readonly ResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return $this->collection();
        }

        if (!$operation instanceof Patch) {
            $this->query->includes(['author']);
        }

        $id = ctype_digit((string) ($uriVariables['id'] ?? '')) ? (int) $uriVariables['id'] : null;

        if (null === $id) {
            return null;
        }

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('b', 'a')
            ->from(Book::class, 'b')
            ->join('b.author', 'a')
            ->where('b.id = :id')
            ->setParameter('id', $id);

        if (!$operation instanceof Patch) {
            $queryBuilder->andWhere('b.published = true');
        }

        $book = $queryBuilder->getQuery()->getOneOrNullResult();

        return null === $book ? null : $this->mapper->books([$book])[0];
    }

    private function collection(): TraversablePaginator
    {
        $this->query->includes(['author']);
        $sorts = $this->query->sort([
            'priceCents' => 'b.priceCents',
            'title' => 'b.title',
            'publishedAt' => 'b.publishedAt',
        ], []);
        [$page, $size] = $this->query->page();

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('b', 'a')
            ->from(Book::class, 'b')
            ->join('b.author', 'a')
            ->where('b.published = true');

        if (null !== $title = $this->query->optionalString('filter[title]')) {
            $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($title));
            $queryBuilder->andWhere("LOWER(b.title) LIKE :title ESCAPE '!'")->setParameter('title', '%'.$escaped.'%');
        }

        if (null !== $author = $this->query->optionalString('filter[author]')) {
            $queryBuilder->andWhere('a.name = :author')->setParameter('author', $author);
        }

        if (null !== $minPrice = $this->query->optionalInteger('filter[minPrice]')) {
            $queryBuilder->andWhere('b.priceCents >= :minPrice')->setParameter('minPrice', $minPrice);
        }

        if (null !== $maxPrice = $this->query->optionalInteger('filter[maxPrice]')) {
            $queryBuilder->andWhere('b.priceCents <= :maxPrice')->setParameter('maxPrice', $maxPrice);
        }

        if (null !== $after = $this->query->date('filter[publishedAfter]')) {
            $queryBuilder->andWhere('b.publishedAt >= :after')->setParameter('after', $after->format('Y-m-d'));
        }

        if (null !== $before = $this->query->date('filter[publishedBefore]')) {
            $queryBuilder->andWhere('b.publishedAt <= :before')->setParameter('before', $before->format('Y-m-d'));
        }

        if (null !== $inStock = $this->query->optionalBoolean('filter[inStock]')) {
            $queryBuilder->andWhere($inStock ? 'b.stock > 0' : 'b.stock = 0');
        }

        foreach ($sorts as [$expression, $direction]) {
            $queryBuilder->addOrderBy($expression, $direction);
        }
        $queryBuilder->addOrderBy('b.id', 'ASC');

        $queryBuilder->setFirstResult(($page - 1) * $size)->setMaxResults($size);
        $paginator = new Paginator($queryBuilder->getQuery(), false);
        $total = \count($paginator);
        $books = iterator_to_array($paginator);

        return new TraversablePaginator(new \ArrayIterator($this->mapper->books($books)), $page, $size, $total);
    }
}
