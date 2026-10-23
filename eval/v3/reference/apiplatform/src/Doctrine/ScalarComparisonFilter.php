<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Filter\FilterInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\BackwardCompatibleFilterDescriptionTrait;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

final readonly class ScalarComparisonFilter implements FilterInterface
{
    use BackwardCompatibleFilterDescriptionTrait;

    public function __construct(private string $operator)
    {
    }

    public function apply(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $parameter = $context['parameter'];
        $name = $queryNameGenerator->generateParameterName($parameter->getProperty());

        $queryBuilder
            ->andWhere(\sprintf('%s.%s %s :%s', $queryBuilder->getRootAliases()[0], $parameter->getProperty(), $this->operator, $name))
            ->setParameter($name, $parameter->getValue());
    }
}
