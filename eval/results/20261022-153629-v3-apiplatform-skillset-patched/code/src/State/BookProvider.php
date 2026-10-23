<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProviderInterface;
use App\Http\PathId;
use App\Http\Paginator;
use App\Http\QueryParameters;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<object|array|null>
 */
final class BookProvider implements ProviderInterface
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

        if ($operation instanceof CollectionOperationInterface) {
            return $this->collection($request);
        }

        $id = PathId::from($uriVariables);
        if (null === $id) {
            return null;
        }

        QueryParameters::include($request, ['author']);

        // Unpublished books are hidden everywhere except PATCH, which works on them too.
        $row = $this->repository->findBook($id, !$operation instanceof Patch);
        if (false === $row) {
            return null;
        }

        return $this->mapper->books([$row])[0];
    }

    private function collection(Request $request): Paginator
    {
        $filters = [
            'title' => QueryParameters::filter($request, 'title'),
            'author' => QueryParameters::filter($request, 'author'),
            'minPrice' => QueryParameters::integerFilter($request, 'minPrice'),
            'maxPrice' => QueryParameters::integerFilter($request, 'maxPrice'),
            'publishedAfter' => QueryParameters::dateFilter($request, 'publishedAfter'),
            'publishedBefore' => QueryParameters::dateFilter($request, 'publishedBefore'),
            'inStock' => QueryParameters::booleanFilter($request, 'inStock'),
        ];
        $sorts = QueryParameters::sort($request, ['priceCents', 'title', 'publishedAt']);
        QueryParameters::include($request, ['author']);
        [$page, $size] = QueryParameters::page($request);

        [$rows, $total] = $this->repository->findPublishedBooks($filters, $sorts, $page, $size);

        return new Paginator($this->mapper->books($rows), $page, $size, $total);
    }
}
