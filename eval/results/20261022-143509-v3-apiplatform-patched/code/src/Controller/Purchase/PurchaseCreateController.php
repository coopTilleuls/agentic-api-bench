<?php

namespace App\Controller\Purchase;

use App\Http\ApiException;
use App\Http\JsonApi;
use App\Sales\CustomerAuthenticator;
use App\Sales\PurchaseDocument;
use App\Sales\PurchaseRepository;
use App\Sales\PurchaseService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseCreateController
{
    public function __construct(
        private readonly CustomerAuthenticator $customers,
        private readonly PurchaseService $purchases,
        private readonly PurchaseRepository $repository,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $customerId = $this->customers->customerId($request);
        if (!JsonApi::hasJsonApiContentType($request)) {
            return JsonApi::error(new ApiException(415, 'The Content-Type must be application/vnd.api+json.'));
        }

        $data = $this->document($request);
        if (($data['type'] ?? null) !== 'purchases') {
            return JsonApi::error(new ApiException(409, 'The data type must be purchases.', '/data/type'));
        }
        $attributes = $data['attributes'] ?? [];
        if (!\is_array($attributes)) {
            throw new ApiException(400, 'The attributes member must be an object.', '/data/attributes');
        }

        $lines = $attributes['lines'] ?? null;
        $purchaseId = $this->purchases->purchase($customerId, \is_array($lines) ? $lines : []);
        $row = $this->repository->find($customerId, $purchaseId);
        $purchaseLines = $this->repository->linesByPurchase([$purchaseId]);

        $baseUrl = JsonApi::baseUrl($request);
        $document = PurchaseDocument::resource($row, $purchaseLines[$purchaseId] ?? [], $baseUrl);

        return JsonApi::document(['data' => $document], 201, ['Location' => $baseUrl.'/purchases/'.$purchaseId]);
    }

    /**
     * @return array<string, mixed> the data member of the request document
     */
    private function document(Request $request): array
    {
        try {
            $body = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ApiException(400, 'The request body is not valid JSON.');
        }
        if (!\is_array($body) || !\is_array($body['data'] ?? null)) {
            throw new ApiException(400, 'The request body must be a JSON:API document with a data member.', '/data');
        }

        return $body['data'];
    }
}
