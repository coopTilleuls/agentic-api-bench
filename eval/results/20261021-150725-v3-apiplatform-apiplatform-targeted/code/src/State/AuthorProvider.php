<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\Error\JsonApiException;
use App\Api\Query\ListQuery;
use App\Entity\Author;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * @implements ProviderInterface<object>
 */
final class AuthorProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ListQuery $query,
        private readonly ResourceMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $this->query->includes(['books']);

        if ($operation instanceof CollectionOperationInterface) {
            $this->query->sort([], []);
            [$page, $size] = $this->query->page();

            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->select('a')
                ->from(Author::class, 'a')
                ->orderBy('a.id', 'ASC')
                ->setFirstResult(($page - 1) * $size)
                ->setMaxResults($size);

            $paginator = new Paginator($queryBuilder->getQuery(), false);
            $total = \count($paginator);
            $authors = iterator_to_array($paginator);

            return new TraversablePaginator(new \ArrayIterator(array_values($this->mapper->authors($authors))), $page, $size, $total);
        }

        $id = ctype_digit((string) ($uriVariables['id'] ?? '')) ? (int) $uriVariables['id'] : null;
        $author = null === $id ? null : $this->entityManager->find(Author::class, $id);

        return null === $author ? null : $this->mapper->authors([$author])[$id];
    }
}
