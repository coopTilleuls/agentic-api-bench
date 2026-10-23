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
 * The customer is identified by the Customer-Id header, checked by CustomerIdentitySubscriber.
 *
 * @implements ProviderInterface<object|array|null>
 */
final class PurchaseProvider implements ProviderInterface
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
        $customerId = $request->attributes->getInt('_customer_id');

        if ($operation instanceof CollectionOperationInterface) {
            [$page, $size] = QueryParameters::page($request);
            $rows = $this->repository->findPurchases($customerId, $size, ($page - 1) * $size);

            return new Paginator($this->mapper->purchases($rows), $page, $size, $this->repository->countPurchases($customerId));
        }

        $id = PathId::from($uriVariables);
        if (null === $id) {
            return null;
        }

        $row = $this->repository->findPurchase($customerId, (int) $id);
        if (false === $row) {
            return null;
        }

        return $this->mapper->purchases([$row])[0];
    }
}
