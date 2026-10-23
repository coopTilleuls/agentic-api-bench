<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Http\PathId;
use App\Http\Paginator;
use App\Http\QueryParameters;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<object|array|null>
 */
final class AuthorProvider implements ProviderInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly BookshopMapper $mapper,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest() ?? throw new \LogicException('A request is required.');
        QueryParameters::include($request, ['books']);

        if ($operation instanceof CollectionOperationInterface) {
            [$page, $size] = QueryParameters::page($request);
            $authors = $this->mapper->authorsFromRows($this->repository->findAuthors(null, $page, $size));

            return new Paginator($authors, $page, $size, $this->repository->countAuthors());
        }

        $id = PathId::from($uriVariables);
        if (null === $id) {
            return null;
        }

        return $this->mapper->authorsByIds([$id])[$id] ?? null;
    }
}
