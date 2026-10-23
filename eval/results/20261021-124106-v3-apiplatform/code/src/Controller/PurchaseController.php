<?php

namespace App\Controller;

use App\Http\ApiException;
use App\Http\JsonApi;
use App\Http\Query;
use App\Http\Resources;
use App\Repository\PurchaseRepository;
use App\Service\PurchaseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PurchaseController
{
    private const SORT = [
        'createdAt' => 'p.created_at',
        'totalCents' => 'p.total_cents',
    ];

    public function __construct(
        private readonly PurchaseRepository $purchases,
        private readonly PurchaseService $service,
    ) {
    }

    #[Route('/purchases', name: 'purchases_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $customerId = $this->customerId($request);
        Query::include($request, []);
        $orders = Query::sort($request, self::SORT);
        if ([] === $orders) {
            $orders = [['p.created_at', 'DESC']];
        }
        [$number, $size] = Query::page($request);

        $total = $this->purchases->countPurchases($customerId);
        $rows = $this->purchases->findPurchases($customerId, $orders, ($number - 1) * $size, $size);
        $lines = $this->purchases->linesOf(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        $data = [];
        foreach ($rows as $row) {
            $data[] = Resources::purchase($row, $lines[(int) $row['id']] ?? []);
        }

        return JsonApi::respond(JsonApi::collection($request, $data, $total, $number, $size));
    }

    #[Route('/purchases/{id}', name: 'purchases_show', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $customerId = $this->customerId($request);
        Query::include($request, []);

        $purchaseId = Query::id($id);
        $row = null === $purchaseId ? null : $this->purchases->findPurchase($customerId, $purchaseId);
        if (null === $row) {
            throw ApiException::one(404, 'Purchase not found.');
        }

        $lines = $this->purchases->linesOf([$purchaseId]);

        return JsonApi::respond(['data' => Resources::purchase($row, $lines[$purchaseId] ?? [])]);
    }

    #[Route('/purchases', name: 'purchases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customerId = $this->customerId($request);
        $data = JsonApi::requestData($request);

        if ('purchases' !== ($data['type'] ?? null)) {
            throw ApiException::one(409, 'The resource type must be purchases.', '/data/type');
        }
        $attributes = $data['attributes'] ?? [];
        if (!is_array($attributes)) {
            throw ApiException::one(400, 'attributes must be an object.', '/data/attributes');
        }

        $purchaseId = $this->service->place($customerId, $this->lines($attributes));

        $row = $this->purchases->findPurchase($customerId, $purchaseId);
        $lines = $this->purchases->linesOf([$purchaseId]);

        return JsonApi::respond(
            ['data' => Resources::purchase($row, $lines[$purchaseId] ?? [])],
            201,
            ['Location' => "/purchases/$purchaseId"],
        );
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return list<array{bookId: int, quantity: int}>
     *
     * @throws ApiException 422 when the lines are missing or malformed
     */
    private function lines(array $attributes): array
    {
        $lines = $attributes['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw ApiException::one(422, 'lines must be a non-empty list of books.', '/data/attributes/lines');
        }

        $valid = [];
        $errors = [];
        foreach ($lines as $index => $line) {
            $base = "/data/attributes/lines/$index";
            if (!is_array($line) || array_is_list($line)) {
                $errors[] = JsonApi::error(422, 'Each line must be an object.', $base);
                continue;
            }
            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!is_int($bookId) || $bookId < 1) {
                $errors[] = JsonApi::error(422, 'bookId must be an integer.', "$base/bookId");
            }
            if (!is_int($quantity) || $quantity < 1) {
                $errors[] = JsonApi::error(422, 'quantity must be an integer of at least 1.', "$base/quantity");
            }
            if (is_int($bookId) && $bookId >= 1 && is_int($quantity) && $quantity >= 1) {
                $valid[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }
        if ([] !== $errors) {
            throw new ApiException(422, $errors);
        }

        return $valid;
    }

    private function customerId(Request $request): int
    {
        $header = $request->headers->get('Customer-Id');
        $customerId = null === $header ? null : Query::id(trim($header));
        if (null === $customerId || !$this->purchases->customerExists($customerId)) {
            throw ApiException::one(401, 'A valid Customer-Id header is required.');
        }

        return $customerId;
    }
}
