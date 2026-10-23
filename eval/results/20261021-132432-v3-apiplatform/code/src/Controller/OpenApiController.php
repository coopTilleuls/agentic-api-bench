<?php

namespace App\Controller;

use App\JsonApi\JsonApi;
use App\OpenApi\Specification;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class OpenApiController
{
    #[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        return JsonApi::respond(Specification::build($request->getSchemeAndHttpHost()));
    }
}
