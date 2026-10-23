<?php

namespace App\Controller;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Serializer\OpenApiNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Serves the OpenAPI 3 document describing every operation of the API.
 */
final class OpenApiController
{
    public function __construct(
        #[Autowire(service: 'api_platform.openapi.factory')]
        private readonly OpenApiFactoryInterface $openApiFactory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route('/openapi.json', name: 'openapi_document', methods: ['GET'])]
    public function __invoke(): Response
    {
        $openApi = ($this->openApiFactory)(['base_url' => '']);

        return new Response(
            $this->serializer->serialize($openApi, OpenApiNormalizer::JSON_FORMAT),
            200,
            ['Content-Type' => 'application/vnd.openapi+json'],
        );
    }
}
