<?php

namespace App\Controller;

use ApiPlatform\Symfony\Action\DocumentationAction;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the OpenAPI document as OpenAPI 3.0, the version API Platform's legacy serializer targets by default
 * is 3.2, which is not accepted here.
 */
final class OpenApiController
{
    public function __construct(
        #[Autowire(service: 'api_platform.action.documentation')]
        private readonly DocumentationAction $documentation,
    ) {
    }

    #[Route('/openapi.json', name: 'openapi_json', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $request->query->set('spec_version', '3.0.0');
        $request->attributes->set('_format', 'jsonopenapi');
        $request->headers->remove('Accept');

        return ($this->documentation)($request);
    }
}
