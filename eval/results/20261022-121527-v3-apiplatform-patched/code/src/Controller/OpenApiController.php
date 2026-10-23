<?php

namespace App\Controller;

use App\OpenApi\Specification;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class OpenApiController
{
    #[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(Specification::document(), 200, [], false);
    }
}
