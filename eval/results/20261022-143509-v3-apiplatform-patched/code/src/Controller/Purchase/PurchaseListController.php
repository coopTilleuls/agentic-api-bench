<?php

namespace App\Controller\Purchase;

use App\Http\JsonApi;
use App\Http\Pagination;
use App\Http\QueryParams;
use App\Sales\CustomerAuthenticator;
use App\Sales\PurchaseDocument;
use App\Sales\PurchaseRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PurchaseListController
{
    public function __construct(
        private readonly CustomerAuthenticator $customers,
        private readonly PurchaseRepository $purchases,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $customerId = $this->customers->customerId($request);
        QueryParams::include($request, []);
        $page = Pagination::fromRequest($request);

        [$rows, $total] = $this->purchases->page($customerId, $page);
        $lines = $this->purchases->linesByPurchase(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        $baseUrl = JsonApi::baseUrl($request);

        return JsonApi::document([
            'data' => array_map(
                static fn (array $row): array => PurchaseDocument::resource($row, $lines[(int) $row['id']] ?? [], $baseUrl),
                $rows,
            ),
            'links' => $page->links($request, $total),
            'meta' => ['totalItems' => $total],
        ]);
    }
}
