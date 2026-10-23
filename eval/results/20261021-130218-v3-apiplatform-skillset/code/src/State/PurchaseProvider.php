<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Purchase;
use App\Http\CustomerIdentificationListener;
use App\Http\QueryParser;
use App\Repository\BookshopRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<Purchase|null>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly QueryParser $queryParser,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator|Purchase|null
    {
        $request = $this->requestStack->getCurrentRequest();
        $customerId = (int) $request->attributes->get(CustomerIdentificationListener::ATTRIBUTE);

        if ($operation instanceof CollectionOperationInterface) {
            $query = $this->queryParser->parseList($request, sortable: [], includable: [], filters: []);

            return new TraversablePaginator(
                new \ArrayIterator(array_map(Purchase::fromRow(...), $this->repository->findPurchases($customerId, $query->offset(), $query->size))),
                (float) $query->page,
                (float) $query->size,
                (float) $this->repository->countPurchases($customerId),
            );
        }

        $this->queryParser->parseInclude($request, []);

        $id = QueryParser::identifier($uriVariables['id'] ?? null);
        $row = null === $id ? null : $this->repository->findPurchase($customerId, $id);

        return null === $row ? null : Purchase::fromRow($row);
    }
}
