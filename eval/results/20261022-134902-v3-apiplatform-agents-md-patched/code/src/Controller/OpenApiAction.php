<?php

namespace App\Controller;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Serializer\OpenApiNormalizer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Serves the OpenAPI document of the API, whatever the Accept header.
 */
final class OpenApiAction
{
    public function __construct(
        private readonly OpenApiFactoryInterface $factory,
        #[Autowire(service: 'api_platform.openapi.normalizer')]
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    public function __invoke(): Response
    {
        $document = $this->normalizer->normalize(($this->factory)(), OpenApiNormalizer::JSON_FORMAT);
        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new Response($json, 200, ['Content-Type' => 'application/json']);
    }
}
