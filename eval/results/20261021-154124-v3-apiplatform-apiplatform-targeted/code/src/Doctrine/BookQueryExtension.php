<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use App\Entity\Book;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Only published books are visible to reads. The filters and the sort are read
 * from the raw query, already validated by RequestGuardListener, so that the
 * bracketed names (filter[minPrice]) are used as written.
 */
final class BookQueryExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    private const SORT_FIELDS = ['priceCents', 'title', 'publishedAt'];

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Book::class !== $resourceClass) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $queryBuilder->andWhere(\sprintf('%s.published = true', $alias));

        $query = $this->requestStack->getCurrentRequest()?->query->all() ?? [];
        $filter = \is_array($query['filter'] ?? null) ? $query['filter'] : [];

        if (\is_string($filter['title'] ?? null) && '' !== $filter['title']) {
            $parameter = $queryNameGenerator->generateParameterName('title');
            $queryBuilder
                ->andWhere(\sprintf('LOWER(%s.title) LIKE :%s', $alias, $parameter))
                ->setParameter($parameter, '%'.addcslashes(mb_strtolower($filter['title']), '%_\\').'%');
        }

        if (\is_string($filter['author'] ?? null) && '' !== $filter['author']) {
            $parameter = $queryNameGenerator->generateParameterName('author');
            $queryBuilder
                ->innerJoin(\sprintf('%s.author', $alias), 'author')
                ->andWhere(\sprintf('author.name = :%s', $parameter))
                ->setParameter($parameter, $filter['author']);
        }

        if (isset($filter['minPrice'])) {
            $parameter = $queryNameGenerator->generateParameterName('minPrice');
            $queryBuilder
                ->andWhere(\sprintf('%s.priceCents >= :%s', $alias, $parameter))
                ->setParameter($parameter, (int) $filter['minPrice']);
        }

        if (isset($filter['maxPrice'])) {
            $parameter = $queryNameGenerator->generateParameterName('maxPrice');
            $queryBuilder
                ->andWhere(\sprintf('%s.priceCents <= :%s', $alias, $parameter))
                ->setParameter($parameter, (int) $filter['maxPrice']);
        }

        if (isset($filter['publishedAfter'])) {
            $parameter = $queryNameGenerator->generateParameterName('publishedAfter');
            $queryBuilder
                ->andWhere(\sprintf('%s.publishedAt >= :%s', $alias, $parameter))
                ->setParameter($parameter, new \DateTimeImmutable($filter['publishedAfter'], new \DateTimeZone('UTC')), Types::DATE_IMMUTABLE);
        }

        if (isset($filter['publishedBefore'])) {
            $parameter = $queryNameGenerator->generateParameterName('publishedBefore');
            $queryBuilder
                ->andWhere(\sprintf('%s.publishedAt <= :%s', $alias, $parameter))
                ->setParameter($parameter, new \DateTimeImmutable($filter['publishedBefore'], new \DateTimeZone('UTC')), Types::DATE_IMMUTABLE);
        }

        if (isset($filter['inStock'])) {
            $queryBuilder->andWhere(\sprintf('true' === $filter['inStock'] ? '%s.stock > 0' : '%s.stock = 0', $alias));
        }

        foreach (self::parseSort($query['sort'] ?? null) as [$field, $direction]) {
            $queryBuilder->addOrderBy(\sprintf('%s.%s', $alias, $field), $direction);
        }

        // Ties are broken by id, and the id order is the default.
        $queryBuilder->addOrderBy(\sprintf('%s.id', $alias), 'ASC');
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        // Unpublished books stay editable through PATCH.
        if (Book::class !== $resourceClass || $operation instanceof Patch) {
            return;
        }

        $queryBuilder->andWhere(\sprintf('%s.published = true', $queryBuilder->getRootAliases()[0]));
    }

    /**
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    private static function parseSort(mixed $sort): array
    {
        if (!\is_string($sort) || '' === $sort) {
            return [];
        }

        $orders = [];
        foreach (explode(',', $sort) as $part) {
            $field = ltrim($part, '-');
            if (\in_array($field, self::SORT_FIELDS, true)) {
                $orders[] = [$field, str_starts_with($part, '-') ? 'DESC' : 'ASC'];
            }
        }

        return $orders;
    }
}
