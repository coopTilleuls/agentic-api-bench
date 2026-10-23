<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\ApiException;
use App\Api\QueryParams;
use App\Entity\Author;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class AuthorProvider implements ProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        /** @var Request $request */
        $request = $context['request'];
        QueryParams::includes($request, ['books']);

        if ($operation instanceof CollectionOperationInterface) {
            $page = QueryParams::page($request);
            $total = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(a.id)')
                ->from(Author::class, 'a')
                ->getQuery()
                ->getSingleScalarResult();
            $authors = $this->entityManager->createQueryBuilder()
                ->select('a')
                ->from(Author::class, 'a')
                ->orderBy('a.id', 'ASC')
                ->setFirstResult(($page['number'] - 1) * $page['size'])
                ->setMaxResults($page['size'])
                ->getQuery()
                ->getResult();

            return new TraversablePaginator(new \ArrayIterator($authors), $page['number'], $page['size'], $total);
        }

        return $this->entityManager->find(Author::class, QueryParams::id($uriVariables['id'] ?? null))
            ?? throw ApiException::status(404, 'Author not found.');
    }
}
