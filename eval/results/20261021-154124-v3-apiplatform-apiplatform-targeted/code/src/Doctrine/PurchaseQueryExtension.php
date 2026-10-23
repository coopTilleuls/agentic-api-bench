<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Customer;
use App\Entity\Purchase;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * A customer only ever sees their own purchases, most recent first.
 */
final class PurchaseQueryExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Purchase::class !== $resourceClass) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $this->restrictToCustomer($queryBuilder, $alias);
        $queryBuilder
            ->addOrderBy(\sprintf('%s.createdAt', $alias), 'DESC')
            ->addOrderBy(\sprintf('%s.id', $alias), 'DESC');
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        if (Purchase::class !== $resourceClass) {
            return;
        }

        $this->restrictToCustomer($queryBuilder, $queryBuilder->getRootAliases()[0]);
    }

    private function restrictToCustomer(QueryBuilder $queryBuilder, string $alias): void
    {
        // The customer is set by RequestGuardListener, which answers 401 before any of this runs.
        $customerId = $this->requestStack->getCurrentRequest()?->attributes->get('_customer_id');
        $queryBuilder
            ->andWhere(\sprintf('%s.customer = :customer', $alias))
            ->setParameter('customer', $customerId ? $this->entityManager->getReference(Customer::class, $customerId) : null);
    }
}
