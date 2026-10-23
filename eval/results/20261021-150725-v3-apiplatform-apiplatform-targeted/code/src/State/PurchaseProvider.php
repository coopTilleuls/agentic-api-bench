<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Api\Query\ListQuery;
use App\Entity\Customer;
use App\Entity\Purchase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The purchases of the customer identified by the Customer-Id header.
 *
 * @implements ProviderInterface<object>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ListQuery $query,
        private readonly ResourceMapper $mapper,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $customer = $this->entityManager->getReference(Customer::class, $this->customerId());

        if ($operation instanceof CollectionOperationInterface) {
            $this->query->includes([]);
            $sorts = $this->query->sort([
                'createdAt' => 'p.createdAt',
                'totalCents' => 'p.totalCents',
            ], [['p.createdAt', 'DESC']]);
            [$page, $size] = $this->query->page();

            $queryBuilder = $this->entityManager->createQueryBuilder()
                ->select('p')
                ->from(Purchase::class, 'p')
                ->where('p.customer = :customer')
                ->setParameter('customer', $customer);

            foreach ($sorts as [$expression, $direction]) {
                $queryBuilder->addOrderBy($expression, $direction);
            }
            $queryBuilder->addOrderBy('p.id', 'DESC');
            $queryBuilder->setFirstResult(($page - 1) * $size)->setMaxResults($size);

            $paginator = new Paginator($queryBuilder->getQuery(), false);
            $total = \count($paginator);
            $purchases = [];

            foreach ($paginator as $purchase) {
                $purchases[] = $this->mapper->purchase($purchase);
            }

            return new TraversablePaginator(new \ArrayIterator($purchases), $page, $size, $total);
        }

        $this->query->includes([]);
        $id = ctype_digit((string) ($uriVariables['id'] ?? '')) ? (int) $uriVariables['id'] : null;

        if (null === $id) {
            return null;
        }

        $purchase = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Purchase::class, 'p')
            ->where('p.id = :id AND p.customer = :customer')
            ->setParameter('id', $id)
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getOneOrNullResult();

        return null === $purchase ? null : $this->mapper->purchase($purchase);
    }

    private function customerId(): int
    {
        return (int) $this->requestStack->getCurrentRequest()?->attributes->get('customer_id');
    }
}
