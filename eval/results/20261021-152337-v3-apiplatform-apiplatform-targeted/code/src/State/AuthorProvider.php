<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Author;
use App\JsonApi\Query;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @implements ProviderInterface<Author>
 */
final class AuthorProvider implements ProviderInterface
{
    private const INCLUDES = ['books'];

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];
        $repository = $this->entityManager->getRepository(Author::class);

        if (!$operation instanceof CollectionOperationInterface) {
            Query::includes($request, self::INCLUDES);
            $authorId = Query::id((string) ($uriVariables['id'] ?? ''));

            return null === $authorId ? null : $repository->find($authorId);
        }

        Query::includes($request, self::INCLUDES);
        [$page, $size] = Query::pagination($request);

        $authors = $repository->findBy([], ['id' => 'ASC'], $size, ($page - 1) * $size);

        return new TraversablePaginator(new \ArrayIterator($authors), (float) $page, (float) $size, (float) $repository->count([]));
    }
}
