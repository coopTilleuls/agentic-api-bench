<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents what API Platform cannot derive from the resources: the request bodies, the
 * error responses of the write operations, and the CSV sales report.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final class BookshopOpenApiFactory implements OpenApiFactoryInterface
{
    private const JSON_API = 'application/vnd.api+json';

    public function __construct(
        #[AutowireDecorated]
        private readonly OpenApiFactoryInterface $inner,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->inner)($context);
        $paths = $openApi->getPaths();

        foreach (['/books', '/authors', '/purchases'] as $path) {
            $item = $paths->getPath($path);
            if (null !== $item?->getGet()) {
                $paths->addPath($path, $item->withGet($item->getGet()->withResponse(400, $this->errors('The request has an invalid query parameter.'))));
            }
        }

        foreach (['/purchases', '/purchases/{id}'] as $path) {
            $item = $paths->getPath($path);
            if (null !== $item?->getGet()) {
                $paths->addPath($path, $item->withGet($item->getGet()->withResponse(401, $this->errors('The Customer-Id header is missing or unknown.'))));
            }
        }

        $item = $paths->getPath('/purchases');
        $post = $item->getPost()
            ->withRequestBody($this->requestBody('Lines of the purchase.', $this->purchaseSchema()))
            ->withResponse(401, $this->errors('The Customer-Id header is missing or unknown.'))
            ->withResponse(402, $this->errors('The payment was declined.'))
            ->withResponse(409, $this->errors('Not enough stock for a book.'))
            ->withResponse(415, $this->errors('The Content-Type is not application/vnd.api+json.'))
            ->withResponse(502, $this->errors('The payment service failed or is unreachable.'));
        $paths->addPath('/purchases', $item->withPost($post));

        $item = $paths->getPath('/books/{id}');
        $patch = $item->getPatch()
            ->withRequestBody($this->requestBody('Attributes to change; only the ones sent are updated.', $this->bookSchema()))
            ->withResponse(204, new Response(description: 'The book is unpublished: nothing to return.'))
            ->withResponse(409, $this->errors('The id in the document does not match the id in the URL.'))
            ->withResponse(415, $this->errors('The Content-Type is not application/vnd.api+json.'));
        $paths->addPath('/books/{id}', $item->withPatch($patch));

        $paths->addPath('/reports/sales', new PathItem(get: $this->salesReport()));

        return $openApi->withPaths($paths);
    }

    private function salesReport(): Operation
    {
        return new Operation(
            operationId: 'getSalesReport',
            tags: ['Reports'],
            summary: 'Sales report as CSV',
            parameters: [
                new Parameter(name: 'from', in: 'query', description: 'First day included (YYYY-MM-DD, UTC).', required: true, schema: ['type' => 'string', 'format' => 'date']),
                new Parameter(name: 'to', in: 'query', description: 'Last day included (YYYY-MM-DD, UTC).', required: true, schema: ['type' => 'string', 'format' => 'date']),
            ],
            responses: [
                '200' => new Response(
                    description: 'CSV file with the columns title, quantity and revenueCents, one line per book sold, by revenue.',
                    content: new \ArrayObject(['text/csv' => ['schema' => ['type' => 'string']]]),
                ),
                '400' => $this->errors('The from or to date is missing, malformed, or from is after to.'),
            ],
        );
    }

    private function requestBody(string $description, array $attributesSchema): RequestBody
    {
        return new RequestBody(
            description: $description,
            content: new \ArrayObject([self::JSON_API => ['schema' => $attributesSchema]]),
            required: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'required' => ['type', 'attributes'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['purchases']],
                        'attributes' => [
                            'type' => 'object',
                            'required' => ['lines'],
                            'properties' => [
                                'lines' => [
                                    'type' => 'array',
                                    'minItems' => 1,
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['bookId', 'quantity'],
                                        'properties' => [
                                            'bookId' => ['type' => 'integer', 'minimum' => 1],
                                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bookSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => [
                    'type' => 'object',
                    'required' => ['type', 'id', 'attributes'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['books']],
                        'id' => ['type' => 'string'],
                        'attributes' => [
                            'type' => 'object',
                            'properties' => [
                                'title' => ['type' => 'string', 'minLength' => 1],
                                'priceCents' => ['type' => 'integer', 'minimum' => 0],
                                'stock' => ['type' => 'integer', 'minimum' => 0],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function errors(string $description): Response
    {
        return new Response(
            description: $description,
            content: new \ArrayObject([self::JSON_API => ['schema' => [
                'type' => 'object',
                'required' => ['errors'],
                'properties' => [
                    'errors' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['status', 'title', 'detail'],
                            'properties' => [
                                'status' => ['type' => 'string'],
                                'title' => ['type' => 'string'],
                                'detail' => ['type' => 'string'],
                                'source' => ['type' => 'object'],
                            ],
                        ],
                    ],
                ],
            ]]]),
        );
    }
}
