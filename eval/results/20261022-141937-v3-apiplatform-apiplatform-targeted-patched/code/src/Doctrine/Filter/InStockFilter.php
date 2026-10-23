<?php

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use Doctrine\ORM\QueryBuilder;

/**
 * filter[inStock]=true keeps books with stock above 0, filter[inStock]=false keeps books with stock 0.
 */
final class InStockFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        /** @var Parameter $parameter */
        $parameter = $context['parameter'];
        $inStock = $parameter->getValue();
        $alias = $queryBuilder->getRootAliases()[0];
        $field = \sprintf('%s.%s', $alias, $parameter->getProperty());

        if ('true' === $inStock) {
            $queryBuilder->andWhere(\sprintf('%s > 0', $field));
        } elseif ('false' === $inStock) {
            $queryBuilder->andWhere(\sprintf('%s = 0', $field));
        }
    }
}
