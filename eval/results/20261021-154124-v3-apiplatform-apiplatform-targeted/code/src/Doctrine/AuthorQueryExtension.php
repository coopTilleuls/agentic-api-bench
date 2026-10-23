<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Author;
use Doctrine\ORM\QueryBuilder;

final class AuthorQueryExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Author::class !== $resourceClass) {
            return;
        }

        $queryBuilder->addOrderBy(\sprintf('%s.id', $queryBuilder->getRootAliases()[0]), 'ASC');
    }
}
