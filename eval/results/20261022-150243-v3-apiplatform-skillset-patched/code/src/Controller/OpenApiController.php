<?php

namespace App\Controller;

use App\JsonApi\JsonApiResponse;
use App\OpenApi\Specification;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OpenApiController
{
    #[Route('/openapi.json', name: 'bookshop_openapi', methods: ['GET'])]
    public function __invoke(): Response
    {
        return JsonApiResponse::document(Specification::document());
    }
}
