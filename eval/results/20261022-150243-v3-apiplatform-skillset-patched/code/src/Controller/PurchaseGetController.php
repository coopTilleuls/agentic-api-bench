<?php

namespace App\Controller;

use App\Bookshop\PurchaseLines;
use App\JsonApi\Input;
use App\JsonApi\JsonApiException;
use App\JsonApi\JsonApiResponse;
use App\JsonApi\Resources;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseGetController
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseLines $lines,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        Input::includes($request, []);

        $purchaseId = BookGetController::id($id);
        $row = $this->db->fetchAssociative(
            'SELECT id, created_at, total_cents FROM purchase WHERE id = ? AND customer_id = ?',
            [$purchaseId, $request->attributes->getInt('customerId')],
        );
        if (false === $row) {
            throw JsonApiException::notFound('Purchase not found.');
        }

        $lines = $this->lines->grouped([$purchaseId])[(string) $purchaseId] ?? [];

        return JsonApiResponse::document(['data' => Resources::purchase($row, $lines)]);
    }
}
