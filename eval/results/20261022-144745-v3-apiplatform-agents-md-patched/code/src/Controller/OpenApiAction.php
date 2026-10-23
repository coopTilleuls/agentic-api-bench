<?php

namespace App\Controller;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Serves the OpenAPI document of the API, whatever the Accept header of the request.
 */
final class OpenApiAction
{
    public function __construct(
        private readonly OpenApiFactoryInterface $openApiFactory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('/openapi.json', name: 'bookshop_openapi', methods: ['GET', 'HEAD'])]
    public function __invoke(): Response
    {
        return new Response(
            $this->serializer->serialize(($this->openApiFactory)(), 'json'),
            Response::HTTP_OK,
            ['Content-Type' => 'application/json'],
        );
    }
}
