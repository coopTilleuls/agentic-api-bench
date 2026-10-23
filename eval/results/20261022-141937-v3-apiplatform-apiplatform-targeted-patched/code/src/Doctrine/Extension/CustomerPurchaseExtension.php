<?php

namespace App\Doctrine\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Customer\CurrentCustomer;
use App\Entity\Purchase;
use Doctrine\ORM\QueryBuilder;

/**
 * A customer only ever sees their own purchases.
 */
final class CustomerPurchaseExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly CurrentCustomer $currentCustomer)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($queryBuilder, $resourceClass, $operation);

        if (Purchase::class === $resourceClass && 'GET' === $operation?->getMethod()) {
            $alias = $queryBuilder->getRootAliases()[0];
            $queryBuilder
                ->addOrderBy(\sprintf('%s.createdAt', $alias), 'DESC')
                ->addOrderBy(\sprintf('%s.id', $alias), 'DESC');
        }
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($queryBuilder, $resourceClass, $operation);
    }

    private function restrict(QueryBuilder $queryBuilder, string $resourceClass, ?Operation $operation): void
    {
        if (Purchase::class !== $resourceClass || 'GET' !== $operation?->getMethod()) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder
            ->andWhere(\sprintf('IDENTITY(%s.customer) = :currentCustomerId', $alias))
            ->setParameter('currentCustomerId', $this->currentCustomer->id());
    }
}
