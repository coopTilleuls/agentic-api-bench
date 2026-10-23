<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class Responses
{
    /**
     * @param array{items: list<array<string, mixed>>, total: int} $result
     */
    public static function list(array $result, int $page, int $perPage): JsonResponse
    {
        return new JsonResponse([
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'itemsPerPage' => $perPage,
        ]);
    }
}
