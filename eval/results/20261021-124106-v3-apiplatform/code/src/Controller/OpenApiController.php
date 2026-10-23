<?php

namespace App\Controller;

use App\Http\JsonApi;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OpenApiController
{
    #[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
    public function __invoke(): Response
    {
        $document = file_get_contents(dirname(__DIR__, 2).'/config/openapi.json');

        return new Response($document, 200, ['Content-Type' => JsonApi::MEDIA_TYPE]);
    }
}
