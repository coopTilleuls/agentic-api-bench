<?php

namespace App\Controller;

use App\JsonApi\ApiException;
use App\JsonApi\Document;
use App\JsonApi\Query;
use App\JsonApi\Resources;
use App\Repository\PurchaseRepository;
use App\Service\PurchaseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PurchaseController
{
    public function __construct(
        private readonly PurchaseRepository $purchases,
        private readonly PurchaseService $service,
    ) {
    }

    #[Route('/purchases', name: 'purchases_list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $customerId = $this->customerId($request);
        [$number, $size] = Query::page($request);
        Query::include($request, []);
        Query::sort($request, []);

        $total = $this->purchases->countFor($customerId);
        $rows = $this->purchases->pageFor($customerId, $size, ($number - 1) * $size);
        $lines = $this->purchases->linesFor(array_column($rows, 'id'));

        return Document::respond([
            'data' => array_map(
                fn (array $row) => Resources::purchase($row, $lines[(int) $row['id']] ?? []),
                $rows,
            ),
            'links' => Query::links($request, $number, $size, $total),
            'meta' => ['totalItems' => $total],
        ]);
    }

    #[Route('/purchases/{id}', name: 'purchases_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $customerId = $this->customerId($request);
        Query::include($request, []);

        $purchaseId = Query::pathId($id);
        $row = null !== $purchaseId ? $this->purchases->findFor($customerId, $purchaseId) : null;
        if (null === $row) {
            throw ApiException::status(404, 'Purchase not found.');
        }

        $lines = $this->purchases->linesFor([$purchaseId]);

        return Document::respond([
            'data' => Resources::purchase($row, $lines[$purchaseId] ?? []),
            'links' => ['self' => "/purchases/$purchaseId"],
        ]);
    }

    #[Route('/purchases', name: 'purchases_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $customerId = $this->customerId($request);
        Document::requireMediaType($request);
        $body = Document::decodeBody($request);

        $data = $body['data'] ?? null;
        if (!\is_array($data)) {
            throw ApiException::status(400, 'The request must contain a data object.');
        }
        if (isset($data['type']) && 'purchases' !== $data['type']) {
            throw ApiException::status(409, 'The type must be purchases.');
        }

        $attributes = \is_array($data['attributes'] ?? null) ? $data['attributes'] : [];
        $lines = $attributes['lines'] ?? null;
        if (!\is_array($lines) || [] === $lines || !array_is_list($lines)) {
            throw new ApiException([
                ApiException::error(422, 'lines must be a non-empty list.', ['pointer' => '/data/attributes/lines']),
            ]);
        }

        $requested = [];
        $errors = [];
        foreach ($lines as $index => $line) {
            $base = "/data/attributes/lines/$index";
            if (!\is_array($line)) {
                $errors[] = ApiException::error(422, 'Each line must be an object.', ['pointer' => $base]);
                continue;
            }
            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            if (!\is_int($bookId) || $bookId < 1) {
                $errors[] = ApiException::error(422, 'bookId must be a positive integer.', ['pointer' => "$base/bookId"]);
            }
            if (!\is_int($quantity) || $quantity < 1) {
                $errors[] = ApiException::error(422, 'quantity must be an integer of at least 1.', ['pointer' => "$base/quantity"]);
            }
            if (\is_int($bookId) && \is_int($quantity) && $bookId >= 1 && $quantity >= 1) {
                $requested[] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }
        if ([] !== $errors) {
            throw new ApiException($errors);
        }

        $purchase = $this->service->buy($customerId, $requested);
        $location = '/purchases/'.$purchase['id'];

        return Document::respond([
            'data' => Resources::purchase(
                ['id' => $purchase['id'], 'created_at' => $purchase['created_at'], 'total_cents' => $purchase['total_cents']],
                $purchase['lines'],
            ),
            'links' => ['self' => $location],
        ], 201, ['Location' => $location]);
    }

    /**
     * The customer identified by the Customer-Id header; 401 when absent or unknown.
     */
    private function customerId(Request $request): int
    {
        $raw = $request->headers->get('Customer-Id');
        $customerId = null !== $raw ? Query::pathId($raw) : null;
        if (null === $customerId || !$this->purchases->customerExists($customerId)) {
            throw ApiException::status(401, 'A valid Customer-Id header is required.');
        }

        return $customerId;
    }
}
