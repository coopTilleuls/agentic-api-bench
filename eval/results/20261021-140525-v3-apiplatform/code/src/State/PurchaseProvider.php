<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Purchase;
use App\EventListener\CustomerIdListener;
use App\Exception\ApiException;
use App\Http\JsonApiQuery;
use App\Pagination\Page;
use App\Service\BookshopRepository;

/**
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
        $customerId = $request->attributes->getInt(CustomerIdListener::ATTRIBUTE);
        JsonApiQuery::include($request, []);

        if ($operation instanceof CollectionOperationInterface) {
            $page = JsonApiQuery::page($request);
            $result = $this->repository->findPurchases($customerId, $page['number'], $page['size']);
            $items = array_map(fn (array $row): Purchase => PurchaseProvider::purchase($row), $result['rows']);

            return new Page($items, $result['total'], $page['number'], $page['size']);
        }

        $row = $this->repository->findPurchase($customerId, (int) $uriVariables['id']);
        if (null === $row) {
            throw ApiException::single(404, 'Not Found', sprintf('Purchase %s not found.', $uriVariables['id']));
        }

        return self::purchase($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function purchase(array $row): Purchase
    {
        $purchase = new Purchase();
        $purchase->id = (int) $row['id'];
        $purchase->createdAt = (new \DateTimeImmutable($row['created_at']))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
        $purchase->totalCents = (int) $row['total_cents'];
        $purchase->lines = array_map(static fn (array $line): array => [
            'bookId' => (int) $line['book_id'],
            'title' => $line['title'],
            'quantity' => (int) $line['quantity'],
            'unitPriceCents' => (int) $line['unit_price_cents'],
        ], $row['lines']);

        return $purchase;
    }
}
