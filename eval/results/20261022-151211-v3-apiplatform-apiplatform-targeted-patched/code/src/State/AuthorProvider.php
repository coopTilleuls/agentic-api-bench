<?php

namespace App\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ListQuery;
use App\Api\PageResult;
use App\Api\ResourceMapper;
use App\Entity\Author;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class AuthorProvider implements ProviderInterface
{
    private const SORT_FIELDS = [
        'id' => 'a.id',
        'name' => 'a.name',
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
        $this->query->include($request, ['books']);

        if ($operation instanceof GetCollection) {
            $page = $this->query->page($request);
            $sorts = $this->query->sort($request, self::SORT_FIELDS);

            $qb = $this->em->createQueryBuilder()->select('a')->from(Author::class, 'a');
            foreach ($sorts as [$column, $direction]) {
                $qb->addOrderBy($column, $direction);
            }
            $qb->addOrderBy('a.id', 'ASC');

            $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
            $authors = $qb
                ->setFirstResult(($page['number'] - 1) * $page['size'])
                ->setMaxResults($page['size'])
                ->getQuery()
                ->getResult();

            return new PageResult(
                array_map(fn (Author $author) => $this->mapper->author($author), $authors),
                $total,
                $page['number'],
                $page['size'],
            );
        }

        $author = $this->em->find(Author::class, (int) $uriVariables['id']);

        return null === $author ? null : $this->mapper->author($author);
    }
}
