<?php

namespace App\Controller;

use App\JsonApi\Document;
use App\OpenApi\Specification;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OpenApiController
{
    #[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response(
            json_encode(Specification::document(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            200,
            ['Content-Type' => Document::MEDIA_TYPE],
        );
    }
}
