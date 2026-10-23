<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

final class SortListFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $alias = $queryBuilder->getRootAliases()[0];

        foreach (explode(',', $context['parameter']->getValue()) as $field) {
            $queryBuilder->addOrderBy(\sprintf('%s.%s', $alias, ltrim($field, '-')), str_starts_with($field, '-') ? 'DESC' : 'ASC');
        }
    }
}
