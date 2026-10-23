<?php

namespace App\Controller;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Serializer\OpenApiNormalizer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * The OpenAPI 3.0 document of every operation: the API Platform resources, plus the sales report, which is a plain route.
 */
#[Route('/openapi.json', name: 'bookshop_openapi', methods: ['GET'])]
final class OpenApiController
{
    private const SPEC_VERSION = '3.0.0';

    /**
     * The error statuses each operation answers, besides its success status.
     */
    private const ERRORS = [
        'get /authors' => ['400'],
        'get /authors/{id}' => ['400', '404'],
        'get /books' => ['400'],
        'get /books/{id}' => ['400', '404'],
        'patch /books/{id}' => ['404', '409', '415', '422'],
        'get /purchases' => ['400', '401'],
        'get /purchases/{id}' => ['401', '404'],
        'post /purchases' => ['400', '401', '402', '409', '415', '422', '502'],
        'get /reports/sales' => ['400'],
    ];

    private const DESCRIPTIONS = [
        '400' => 'A query parameter or the request body is invalid.',
        '401' => 'The Customer-Id header is missing or does not identify a known customer.',
        '402' => 'The payment was declined.',
        '404' => 'Not found, or not visible to the caller.',
        '409' => 'Conflict: the id does not match the path, or not enough stock.',
        '415' => 'The Content-Type is not application/vnd.api+json.',
        '422' => 'Validation errors, one per invalid field.',
        '502' => 'The payment service is unreachable or answered unexpectedly.',
    ];

    public function __construct(
        private readonly OpenApiFactoryInterface $openApiFactory,
        private readonly SerializerInterface $serializer,
    ) {
    }

    public function __invoke(): Response
    {
        $context = ['spec_version' => self::SPEC_VERSION];
        $document = json_decode($this->serializer->serialize(($this->openApiFactory)($context), OpenApiNormalizer::FORMAT, $context), true, 512, \JSON_THROW_ON_ERROR);

        $document['paths']['/reports/sales'] = [
            'get' => [
                'operationId' => 'getSalesReport',
                'summary' => 'Sales report of the books sold between two days, as CSV.',
                'parameters' => [
                    ['name' => 'from', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'First day, included (YYYY-MM-DD, UTC).'],
                    ['name' => 'to', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Last day, included (YYYY-MM-DD, UTC).'],
                ],
                'responses' => ['200' => ['description' => 'CSV with the columns title, quantity, revenueCents.', 'content' => ['text/csv' => ['schema' => ['type' => 'string']]]]],
            ],
        ];

        $document['paths'] = array_map(fn (array $item): array => $this->withoutPaginationAliases($item), $document['paths']);
        foreach ($document['paths'] as $path => $item) {
            foreach (array_keys(array_intersect_key($item, array_flip(['get', 'post', 'patch', 'put', 'delete']))) as $method) {
                foreach (self::ERRORS[$method.' '.$path] ?? [] as $status) {
                    $document['paths'][$path][$method]['responses'][$status] = [
                        'description' => self::DESCRIPTIONS[$status],
                        'content' => ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/JsonApiErrors']]],
                    ];
                }
            }
        }
        $document['paths']['/books/{id}']['patch']['responses']['204'] = ['description' => 'The book is unpublished: the update is applied and no document is returned.'];
        $document['paths']['/reports/sales']['get']['responses']['400'] = [
            'description' => 'The from or to date is missing, malformed, or from is after to.',
            'content' => ['application/vnd.api+json' => ['schema' => ['$ref' => '#/components/schemas/JsonApiErrors']]],
        ];

        $document['components']['schemas']['JsonApiErrors'] = [
            'type' => 'object',
            'required' => ['errors'],
            'properties' => ['errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'detail' => ['type' => 'string'],
                'source' => ['type' => 'object', 'properties' => ['pointer' => ['type' => 'string'], 'parameter' => ['type' => 'string']]],
            ]]]],
        ];

        return new Response(json_encode($document, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES), Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.api+json',
        ]);
    }

    /**
     * API Platform documents its own pagination names (number, size) next to page[number] and page[size].
     *
     * @param array<string, mixed> $pathItem
     *
     * @return array<string, mixed>
     */
    private function withoutPaginationAliases(array $pathItem): array
    {
        foreach (array_keys(array_intersect_key($pathItem, array_flip(['get', 'post', 'patch', 'put', 'delete']))) as $method) {
            $pathItem[$method]['parameters'] = array_values(array_filter(
                $pathItem[$method]['parameters'] ?? [],
                static fn (array $parameter): bool => !\in_array($parameter['name'], ['number', 'size'], true),
            ));
        }

        return $pathItem;
    }
}
