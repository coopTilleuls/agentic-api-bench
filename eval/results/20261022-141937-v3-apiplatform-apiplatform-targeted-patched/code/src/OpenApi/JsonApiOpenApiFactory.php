<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Adjusts the generated document to the JSON:API conventions of this API: the page parameters are named
 * page[number] and page[size], the filter[...] arrays are single values, the Customer-Id header is documented
 * on purchases, and the error responses of each operation are listed.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final class JsonApiOpenApiFactory implements OpenApiFactoryInterface
{
    private const ERROR_SCHEMA = ['$ref' => '#/components/schemas/Error.jsonapi'];

    public function __construct(private readonly OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $paths = new Paths();
        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $paths->addPath($path, $this->fixPathItem($path, $pathItem));
        }

        return $openApi->withPaths($paths);
    }

    private function fixPathItem(string $path, PathItem $pathItem): PathItem
    {
        return $pathItem
            ->withGet($this->fixOperation($path, 'get', $pathItem->getGet()))
            ->withPost($this->fixOperation($path, 'post', $pathItem->getPost()))
            ->withPatch($this->fixOperation($path, 'patch', $pathItem->getPatch()));
    }

    private function fixOperation(string $path, string $method, ?Operation $operation): ?Operation
    {
        if (null === $operation) {
            return null;
        }

        $parameters = [];
        foreach ($operation->getParameters() ?? [] as $parameter) {
            if ('query' === $parameter->getIn() && str_ends_with($parameter->getName(), '][]')) {
                continue;
            }
            if ('query' === $parameter->getIn() && 'number' === $parameter->getName()) {
                $parameter = $parameter->withName('page[number]');
            }
            if ('query' === $parameter->getIn() && 'size' === $parameter->getName()) {
                $parameter = $parameter->withName('page[size]');
            }
            $parameters[$parameter->getIn().':'.$parameter->getName()] ??= $parameter;
        }

        if ('get' === $method && !str_contains($path, '{id}')) {
            if (\in_array($path, ['/books', '/authors', '/purchases'], true)) {
                $parameters['query:page[number]'] ??= new Parameter('page[number]', 'query', 'The page number, from 1.', false, false, null, ['type' => 'integer', 'minimum' => 1, 'default' => 1]);
                $parameters['query:page[size]'] ??= new Parameter('page[size]', 'query', 'The number of items per page, from 1 to 50.', false, false, null, ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]);
            }
        }

        if (str_starts_with($path, '/purchases')) {
            $parameters['header:Customer-Id'] = new Parameter('Customer-Id', 'header', 'The id of the customer, as given at registration.', true, false, null, ['type' => 'string']);
        }

        $responses = $operation->getResponses() ?? [];
        foreach ($this->extraResponses($path, $method) as $status => $response) {
            if ('200' === (string) $status) {
                $responses[$status] = $response;
            } else {
                $responses[$status] ??= $response;
            }
        }

        return $operation->withParameters(array_values($parameters))->withResponses($responses);
    }

    /**
     * @return array<string, Response>
     */
    private function extraResponses(string $path, string $method): array
    {
        $responses = [];
        $jsonApi = static fn (array $schema): \ArrayObject => new \ArrayObject(['application/vnd.api+json' => ['schema' => $schema]]);

        if ('get' === $method && !str_contains($path, '{id}')) {
            $responses['400'] = new Response('Invalid query parameter: the error names the parameter.', $jsonApi(self::ERROR_SCHEMA));
        }
        if ('get' === $method && str_contains($path, '{id}') && \in_array($path, ['/books/{id}', '/authors/{id}'], true)) {
            $responses['400'] = new Response('Invalid query parameter: the error names the parameter.', $jsonApi(self::ERROR_SCHEMA));
        }
        if (str_starts_with($path, '/purchases')) {
            $responses['401'] = new Response('The Customer-Id header is missing, malformed or unknown.', $jsonApi(self::ERROR_SCHEMA));
        }
        if ('post' === $method && '/purchases' === $path) {
            $responses['402'] = new Response('The payment was declined.', $jsonApi(self::ERROR_SCHEMA));
            $responses['409'] = new Response('Not enough stock for a book.', $jsonApi(self::ERROR_SCHEMA));
            $responses['422'] = new Response('A line is invalid or references an unpublished or unknown book.', $jsonApi(self::ERROR_SCHEMA));
            $responses['502'] = new Response('The payment service is unavailable or gave an invalid answer.', $jsonApi(self::ERROR_SCHEMA));
        }
        if ('patch' === $method) {
            $responses['204'] = new Response('The book is unpublished: the update is saved and there is no body.');
            $responses['409'] = new Response('The id in the document does not match the id in the path.', $jsonApi(self::ERROR_SCHEMA));
            $responses['415'] = new Response('The content type is not application/vnd.api+json.', $jsonApi(self::ERROR_SCHEMA));
        }
        if ('/reports/sales' === $path) {
            $responses['200'] = new Response('The sales report, as CSV.', new \ArrayObject(['text/csv' => ['schema' => ['type' => 'string']]]));
        }

        return $responses;
    }
}
