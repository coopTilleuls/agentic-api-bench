<?php

namespace App\Controller\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * GET /openapi.json: the OpenAPI 3.0 document describing every operation.
 */
#[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
final class OpenApiController
{
    public function __construct(
        private readonly OpenApiFactoryInterface $factory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function __invoke(): Response
    {
        $context = ['spec_version' => '3.0.0'];
        $document = $this->serializer->serialize(($this->factory)($context), 'jsonopenapi', $context);

        return new Response($document, 200, ['Content-Type' => 'application/json']);
    }
}
