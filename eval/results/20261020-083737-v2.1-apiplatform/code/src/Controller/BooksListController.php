<?php

namespace App\Controller;

use App\Bookshop\Catalog;
use App\Http\ApiError;
use App\Http\Input;
use App\Http\Responses;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class BooksListController
{
    public function __construct(private readonly Catalog $catalog)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        ['page' => $page, 'perPage' => $perPage] = Input::pagination($request, 20);

        $price = Input::range($request, 'priceCents');
        $published = Input::range($request, 'publishedAt');

        $filters = [
            'q' => Input::optionalString($request, 'q'),
            'title' => Input::optionalString($request, 'title'),
            'author' => Input::optionalString($request, 'author'),
            'priceGte' => isset($price['gte']) ? Input::integerValue($price['gte'], 'priceCents[gte]') : null,
            'priceLte' => isset($price['lte']) ? Input::integerValue($price['lte'], 'priceCents[lte]') : null,
            'publishedAfter' => isset($published['after']) ? Input::date($published['after'], 'publishedAt[after]')->format('Y-m-d') : null,
            'publishedBefore' => isset($published['before']) ? Input::date($published['before'], 'publishedAt[before]')->format('Y-m-d') : null,
            'inStock' => $this->inStock($request),
        ];

        return Responses::list($this->catalog->books($filters, $this->orders($request), $page, $perPage), $page, $perPage);
    }

    private function inStock(Request $request): ?bool
    {
        return match (Input::optionalString($request, 'inStock')) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw new ApiError(400, '"inStock" must be true or false.'),
        };
    }

    /**
     * @return list<array{string, string}> [column, direction] pairs in the order given
     */
    private function orders(Request $request): array
    {
        $order = $request->query->all()['order'] ?? [];
        if (!\is_array($order)) {
            throw new ApiError(400, 'Invalid "order" parameter.');
        }

        $orders = [];
        foreach ($order as $field => $direction) {
            if (!isset(Catalog::SORTABLE[$field]) || !\is_string($direction) || !\in_array($direction, ['asc', 'desc'], true)) {
                throw new ApiError(400, 'Invalid "order" parameter.');
            }
            $orders[] = [$field, $direction];
        }

        return $orders;
    }
}
