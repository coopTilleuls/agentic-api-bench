<?php

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Yaml\Yaml;

final class OpenApiController
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private readonly string $projectDir)
    {
    }

    #[Route('/openapi.json', name: 'openapi', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $specification = Yaml::parseFile($this->projectDir.'/config/openapi.yaml', Yaml::PARSE_OBJECT_FOR_MAP);

        return new JsonResponse($specification, 200, ['Content-Type' => 'application/json']);
    }
}
