<?php

namespace App\Controller;

use App\Bookshop\BookshopRepository;
use App\Bookshop\PurchaseService;
use App\JsonApi\JsonApi;
use App\JsonApi\JsonApiException;
use App\JsonApi\Resources;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

final class PurchaseController
{
    public function __construct(
        private readonly BookshopRepository $repository,
        private readonly PurchaseService $purchases,
    ) {
    }

    #[Route('/purchases', name: 'purchases_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        JsonApi::includes($request, []);
        $page = JsonApi::page($request);
        $result = $this->repository->purchasesOf($customerId, $page);

        return JsonApi::document([
            'data' => $this->resources($result['rows']),
            'links' => JsonApi::pageLinks($request, $page, $result['total']),
            'meta' => ['totalItems' => $result['total']],
        ]);
    }

    #[Route('/purchases/{id}', name: 'purchases_show', methods: ['GET'])]
    public function show(string $id, Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        JsonApi::includes($request, []);
        $purchaseId = JsonApi::id($id);
        $purchase = null === $purchaseId ? null : $this->repository->findPurchase($purchaseId, $customerId);
        if (null === $purchase) {
            throw new NotFoundHttpException(sprintf('Purchase "%s" not found.', $id));
        }

        return JsonApi::document(['data' => $this->resources([$purchase])[0]]);
    }

    #[Route('/purchases', name: 'purchases_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $customerId = $this->customerId($request);
        if (!JsonApi::hasJsonApiContentType($request)) {
            throw JsonApiException::one(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }

        $lines = $this->lines(JsonApi::body($request));
        $purchaseId = $this->purchases->buy($customerId, $lines);
        $purchase = $this->repository->findPurchase($purchaseId, $customerId);

        return JsonApi::document(
            ['data' => $this->resources([$purchase])[0]],
            201,
            ['Location' => "/purchases/$purchaseId"],
        );
    }

    /**
     * Identifies the customer from the Customer-Id header, answering 401 when it is missing or unknown.
     */
    private function customerId(Request $request): int
    {
        $customerId = JsonApi::id((string) $request->headers->get('Customer-Id', ''));
        if (null === $customerId || !$this->repository->customerExists($customerId)) {
            throw JsonApiException::one(401, 'Unauthorized', 'A valid Customer-Id header is required.');
        }

        return $customerId;
    }

    /**
     * @param list<array<string, mixed>> $purchases purchase rows
     *
     * @return list<array<string, mixed>>
     */
    private function resources(array $purchases): array
    {
        $lines = [];
        foreach ($this->repository->purchaseLines(array_map('intval', array_column($purchases, 'id'))) as $line) {
            $lines[(int) $line['purchase_id']][] = $line;
        }

        return array_map(
            static fn (array $purchase): array => Resources::purchase($purchase, $lines[(int) $purchase['id']] ?? []),
            $purchases,
        );
    }

    /**
     * Validates the document and returns the purchase lines.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array{bookId: int, quantity: int}>
     */
    private function lines(array $body): array
    {
        $data = $body['data'] ?? null;
        if (!is_array($data) || array_is_list($data)) {
            throw JsonApiException::one(422, 'Invalid document', 'The document must contain a data object.', '/data');
        }
        if (!array_key_exists('type', $data)) {
            throw JsonApiException::one(422, 'Invalid document', 'The data object must contain a type.', '/data/type');
        }
        if ('purchases' !== $data['type']) {
            throw JsonApiException::one(409, 'Conflict', 'The resource type must be purchases.', '/data/type');
        }

        $attributes = $data['attributes'] ?? null;
        if (!is_array($attributes)) {
            throw JsonApiException::one(422, 'Invalid document', 'The attributes must be an object.', '/data/attributes');
        }
        $lines = $attributes['lines'] ?? null;
        if (!is_array($lines) || !array_is_list($lines) || [] === $lines) {
            throw JsonApiException::one(422, 'Invalid attribute', 'lines must be a non-empty list.', '/data/attributes/lines');
        }

        $errors = [];
        $valid = [];
        foreach ($lines as $index => $line) {
            $pointer = "/data/attributes/lines/$index";
            if (!is_array($line)) {
                $errors[] = JsonApi::error(422, 'Invalid line', 'Each line must be an object.', $pointer);
                continue;
            }

            $bookId = $line['bookId'] ?? null;
            $quantity = $line['quantity'] ?? null;
            $lineValid = true;
            if (!is_int($bookId) || $bookId < 1 || $bookId > 2147483647) {
                $errors[] = JsonApi::error(422, 'Invalid line', 'bookId must be an integer.', "$pointer/bookId");
                $lineValid = false;
            }
            if (!is_int($quantity) || $quantity < 1) {
                $errors[] = JsonApi::error(422, 'Invalid line', 'quantity must be an integer of at least 1.', "$pointer/quantity");
                $lineValid = false;
            }
            if ($lineValid) {
                $valid[$index] = ['bookId' => $bookId, 'quantity' => $quantity];
            }
        }
        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        $published = array_flip($this->repository->publishedBookIds(array_values(array_unique(array_column($valid, 'bookId')))));
        foreach ($valid as $index => $line) {
            if (!isset($published[$line['bookId']])) {
                $errors[] = JsonApi::error(422, 'Unavailable book', sprintf('Book %d is not available.', $line['bookId']), "/data/attributes/lines/$index/bookId");
            }
        }
        if ([] !== $errors) {
            throw new JsonApiException(422, $errors);
        }

        return array_values($valid);
    }
}
