<?php

namespace App\State;

use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Api\ListQuery;
use App\Api\PageResult;
use App\Api\ResourceMapper;
use App\Entity\Purchase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Only the purchases of the customer named by the Customer-Id header (checked by RequestGuard).
 */
final class PurchaseProvider implements ProviderInterface
{
    private const SORT_FIELDS = [
        'id' => 'p.id',
        'createdAt' => 'p.createdAt',
        'totalCents' => 'p.totalCents',
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
        $customerId = (int) $request->attributes->get('_customer_id');
        $this->query->include($request, []);

        if ($operation instanceof GetCollection) {
            $page = $this->query->page($request);
            $sorts = $this->query->sort($request, self::SORT_FIELDS);
            if ([] === $sorts) {
                $sorts = [['p.createdAt', 'DESC'], ['p.id', 'DESC']];
            }

            $qb = $this->em->createQueryBuilder()
                ->select('p')
                ->from(Purchase::class, 'p')
                ->where('IDENTITY(p.customer) = :customer')
                ->setParameter('customer', $customerId);
            foreach ($sorts as [$column, $direction]) {
                $qb->addOrderBy($column, $direction);
            }
            $qb->addOrderBy('p.id', 'ASC');

            $total = (int) $this->countQuery($qb)->getSingleScalarResult();
            $purchases = $qb
                ->setFirstResult(($page['number'] - 1) * $page['size'])
                ->setMaxResults($page['size'])
                ->getQuery()
                ->getResult();

            return new PageResult(
                array_map(fn (Purchase $purchase) => $this->mapper->purchase($purchase), $purchases),
                $total,
                $page['number'],
                $page['size'],
            );
        }

        $purchase = $this->em->find(Purchase::class, (int) $uriVariables['id']);
        if (null === $purchase || $purchase->getCustomer()->getId() !== $customerId) {
            return null;
        }

        return $this->mapper->purchase($purchase);
    }

    private function countQuery(QueryBuilder $qb): \Doctrine\ORM\Query
    {
        return (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery();
    }
}
