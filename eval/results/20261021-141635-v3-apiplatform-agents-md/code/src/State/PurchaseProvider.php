<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Purchase;
use App\JsonApi\Paginator;
use App\JsonApi\QueryParameters;
use App\Repository\BookshopRepository;

/**
 * The purchases of the customer identified by the Customer-Id header.
 *
 * @implements ProviderInterface<Purchase>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(private readonly BookshopRepository $repository)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $context['request'];
        $customerId = $request->attributes->getInt('customer_id');
        $query = new QueryParameters($request);

        if ($operation instanceof CollectionOperationInterface) {
            $page = $query->page();
            $query->sort([]);
            $query->include([]);

            $rows = $this->repository->purchasesOf($customerId, $page['size'], ($page['number'] - 1) * $page['size']);

            return new Paginator(
                $this->withLines($rows),
                $this->repository->purchaseCountOf($customerId),
                $page['number'],
                $page['size'],
            );
        }

        $query->include([]);
        $row = $this->repository->purchaseOf($customerId, (int) $uriVariables['id']);

        return false === $row ? null : $this->withLines([$row])[0];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Purchase>
     */
    private function withLines(array $rows): array
    {
        $lines = [];
        foreach ($this->repository->linesOf(array_map(static fn (array $row): int => (int) $row['id'], $rows)) as $line) {
            $lines[$line['purchase_id']][] = $line;
        }

        return array_map(
            static fn (array $row): Purchase => Mapper::purchase($row, $lines[$row['id']] ?? []),
            $rows,
        );
    }
}
