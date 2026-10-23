<?php

namespace App\Doctrine\Filter;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Parameter;
use Doctrine\ORM\QueryBuilder;

/**
 * Compares a column with the value of a parameter, for range bounds such as filter[minPrice].
 *
 * The value is already validated as an integer (type "int") or a YYYY-MM-DD date (type "date").
 */
final class ColumnBoundFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function __construct(
        private readonly string $operator,
        private readonly string $type,
    ) {
    }

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        /** @var Parameter $parameter */
        $parameter = $context['parameter'];
        $value = $parameter->getValue();
        if (null === $value || '' === $value) {
            return;
        }

        $property = $parameter->getProperty();
        $alias = $queryBuilder->getRootAliases()[0];
        $name = $queryNameGenerator->generateParameterName($property);

        $queryBuilder
            ->andWhere(\sprintf('%s.%s %s :%s', $alias, $property, $this->operator, $name))
            ->setParameter($name, 'int' === $this->type ? (int) $value : (string) $value);
    }
}
