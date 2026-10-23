<?php

namespace App\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Customer\CustomerIdentifier;
use App\JsonApi\JsonApiDocument;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiQuery;
use App\JsonApi\ResourceObjects;
use App\Repository\PurchaseRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves GET /purchases and GET /purchases/{id}: the purchases of the customer making the request.
 *
 * @implements ProviderInterface<Response>
 */
final class PurchaseProvider implements ProviderInterface
{
    public function __construct(
        private readonly PurchaseRepository $purchases,
        private readonly CustomerIdentifier $customers,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        /** @var Request $request */
        $request = $context['request'];
        $customerId = $this->customers->identify($request);

        return $operation instanceof CollectionOperationInterface
            ? $this->collection($request, $customerId)
            : $this->item($request, $customerId, (string) ($uriVariables['id'] ?? ''));
    }

    private function collection(Request $request, int $customerId): Response
    {
        JsonApiQuery::include($request, []);
        JsonApiQuery::sort($request, []);
        [$number, $size] = JsonApiQuery::page($request);

        $page = $this->purchases->findPageForCustomer($customerId, ($number - 1) * $size, $size);
        $lines = $this->purchases->findLines(array_map(static fn (array $row): int => (int) $row['id'], $page['items']));

        return JsonApiDocument::response([
            'data' => array_map(
                static fn (array $row): array => ResourceObjects::purchase($row, $lines[(int) $row['id']] ?? []),
                $page['items'],
            ),
            'links' => JsonApiQuery::pageLinks($request, $number, $size, $page['total']),
            'meta' => ['totalItems' => $page['total']],
        ]);
    }

    private function item(Request $request, int $customerId, string $id): Response
    {
        JsonApiQuery::include($request, []);

        $purchaseId = JsonApiQuery::id($id);
        $purchase = null === $purchaseId ? null : $this->purchases->findForCustomer($purchaseId, $customerId);
        if (null === $purchase) {
            throw JsonApiException::create(404, 'Purchase not found.');
        }

        $lines = $this->purchases->findLines([$purchaseId]);

        return JsonApiDocument::response(JsonApiDocument::single(ResourceObjects::purchase($purchase, $lines[$purchaseId] ?? [])));
    }
}
