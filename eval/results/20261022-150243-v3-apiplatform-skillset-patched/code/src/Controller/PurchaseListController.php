<?php

namespace App\Controller;

use App\Bookshop\PurchaseLines;
use App\JsonApi\Input;
use App\JsonApi\JsonApiResponse;
use App\JsonApi\Page;
use App\JsonApi\Resources;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PurchaseListController
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseLines $lines,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        Input::includes($request, []);

        $customerId = $request->attributes->getInt('customerId');
        $page = Page::fromRequest($request);

        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM purchase WHERE customer_id = ?', [$customerId]);
        $rows = $this->db->fetchAllAssociative(
            sprintf(
                'SELECT id, created_at, total_cents FROM purchase WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
                $page->size,
                $page->offset(),
            ),
            [$customerId],
        );

        $lines = $this->lines->grouped(array_column($rows, 'id'));
        $data = array_map(
            static fn (array $row): array => Resources::purchase($row, $lines[(string) $row['id']] ?? []),
            $rows,
        );

        return JsonApiResponse::document([
            'data' => $data,
            'links' => $page->links($request, $total),
            'meta' => ['totalItems' => $total],
        ]);
    }
}
