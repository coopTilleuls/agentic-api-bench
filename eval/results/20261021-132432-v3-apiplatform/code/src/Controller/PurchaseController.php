<?php

namespace App\Controller;

use App\Http\ApiException;
use App\JsonApi\JsonApi;
use App\JsonApi\Query;
use App\JsonApi\Resources;
use App\Repository\PurchaseRepository;
use App\Service\Checkout;
use App\Service\CustomerIdentity;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The purchases of the customer named by the Customer-Id header.
 */
#[Route('/purchases')]
final class PurchaseController
{
    public function __construct(
        private readonly CustomerIdentity $identity,
        private readonly PurchaseRepository $purchases,
        private readonly Checkout $checkout,
    ) {
    }

    #[Route('', name: 'purchases_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $customerId = $this->identity->require($request);
        [$number, $size] = Query::page($request);
        Query::include($request, []);
        Query::sort($request, []);

        [$rows, $total] = $this->purchases->paginateFor($customerId, Query::offset($number, $size), $size);
        $lines = $this->purchases->linesFor(array_map(static fn (array $row): int => (int) $row['id'], $rows));

        return JsonApi::respond([
            'data' => array_map(
                fn (array $row): array => Resources::purchase($row, $lines[(int) $row['id']] ?? [], $request),
                $rows,
            ),
        ] + JsonApi::page($request, $number, $size, $total));
    }

    #[Route('/{id}', name: 'purchases_show', methods: ['GET'])]
    public function show(Request $request, string $id): JsonResponse
    {
        $customerId = $this->identity->require($request);

        $purchaseId = Query::id($id);
        $row = null === $purchaseId ? null : $this->purchases->findFor($customerId, $purchaseId);
        if (null === $row) {
            throw ApiException::error(404, 'The purchase does not exist.');
        }

        $lines = $this->purchases->linesFor([$purchaseId]);

        return JsonApi::respond([
            'data' => Resources::purchase($row, $lines[$purchaseId] ?? [], $request),
            'links' => ['self' => JsonApi::absolute($request, '/purchases/'.$purchaseId)],
        ]);
    }

    #[Route('', name: 'purchases_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $customerId = $this->identity->require($request);
        $document = JsonApi::requestDocument($request);

        $lines = $this->validatedLines($document);
        $purchase = $this->checkout->buy($customerId, $lines);

        $url = JsonApi::absolute($request, '/purchases/'.$purchase['id']);

        return JsonApi::respond([
            'data' => Resources::purchase([
                'id' => $purchase['id'],
                'created_at' => $purchase['created_at'],
                'total_cents' => $purchase['total_cents'],
            ], $purchase['lines'], $request),
            'links' => ['self' => $url],
        ], 201, ['Location' => $url]);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return list<array{bookId: int, quantity: int}>
     */
    private function validatedLines(array $document): array
    {
        $data = $document['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw ApiException::error(422, 'The request must have a data object.', pointer: '/data');
        }
        if (isset($data['type']) && 'purchases' !== $data['type']) {
            throw ApiException::error(409, 'The data type must be purchases.');
        }

        $attributes = $data['attributes'] ?? null;
        if (!is_array($attributes) || (array_is_list($attributes) && [] !== $attributes)) {
            throw ApiException::error(422, 'The attributes must be an object.', pointer: '/data/attributes');
        }

        $lines = $attributes['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw ApiException::error(422, 'lines must be a non-empty list.', pointer: '/data/attributes/lines');
        }

        $errors = [];
        $validated = [];
        foreach ($lines as $index => $line) {
            $base = '/data/attributes/lines/'.$index;
            if (!is_array($line) || array_is_list($line)) {
                $errors[] = self::invalid($base, 'A line must be an object.');
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $valid = true;
            if (!is_int($bookId) || $bookId < 1 || $bookId > Query::MAX_INT) {
                $errors[] = self::invalid($base.'/bookId', 'bookId must be a book id.');
                $valid = false;
            }
            if (!is_int($quantity) || $quantity < 1 || $quantity > Query::MAX_INT) {
                $errors[] = self::invalid($base.'/quantity', 'quantity must be an integer of at least 1.');
                $valid = false;
            }
            if ($valid) {
                $validated[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }
        if ([] !== $errors) {
            throw new ApiException(422, $errors);
        }

        return $validated;
    }

    /** @return array<string, mixed> */
    private static function invalid(string $pointer, string $detail): array
    {
        return ['status' => '422', 'title' => Response::$statusTexts[422], 'detail' => $detail, 'source' => ['pointer' => $pointer]];
    }
}
