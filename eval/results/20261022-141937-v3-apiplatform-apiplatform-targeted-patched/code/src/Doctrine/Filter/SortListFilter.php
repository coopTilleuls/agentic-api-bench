<?php

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use Doctrine\ORM\QueryBuilder;

/**
 * Orders by a comma separated list of fields, in the order given, "-" meaning descending.
 * Ties are broken by id ascending. Fields are validated before this filter runs.
 */
final class SortListFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        /** @var Parameter $parameter */
        $parameter = $context['parameter'];
        $sort = $parameter->getValue();
        if (!\is_string($sort) || '' === $sort) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        foreach (explode(',', $sort) as $item) {
            $direction = str_starts_with($item, '-') ? 'DESC' : 'ASC';
            $field = ltrim($item, '-');
            if ('id' === $field) {
                continue;
            }

            $queryBuilder->addOrderBy(\sprintf('%s.%s', $alias, $field), $direction);
        }

        $queryBuilder->addOrderBy(\sprintf('%s.id', $alias), 'ASC');
    }
}
