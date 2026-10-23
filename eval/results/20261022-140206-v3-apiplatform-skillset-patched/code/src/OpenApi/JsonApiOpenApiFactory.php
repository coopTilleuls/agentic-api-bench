<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Completes the generated OpenAPI document with the JSON:API responses and request bodies of each operation.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final class JsonApiOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private readonly OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        foreach (self::operations() as $path => $methods) {
            $pathItem = $paths->getPath($path);
            if (null === $pathItem) {
                throw new \LogicException(\sprintf('The OpenAPI document has no path "%s".', $path));
            }

            foreach ($methods as $method => $documentation) {
                $operation = $this->operation($pathItem, $method)
                    ->withSummary($documentation['summary'])
                    ->withResponses($documentation['responses']);
                if (isset($documentation['requestBody'])) {
                    $operation = $operation->withRequestBody($documentation['requestBody']);
                }
                // The JSON:API pagination is documented as page[number] and page[size], not as the generic page parameter.
                $parameters = array_filter($operation->getParameters() ?? [], static fn (Parameter $parameter): bool => 'page' !== $parameter->getName());
                $required = $documentation['required'] ?? [];
                $operation = $operation->withParameters(array_values(array_map(
                    static fn (Parameter $parameter): Parameter => \in_array($parameter->getName(), $required, true) ? $parameter->withRequired(true) : $parameter,
                    $parameters,
                )));

                $pathItem = $this->withOperation($pathItem, $method, $operation);
            }

            $paths->addPath($path, $pathItem);
        }

        return $openApi->withOpenapi('3.1.0');
    }

    private function operation(PathItem $pathItem, string $method): Operation
    {
        return match ($method) {
            'get' => $pathItem->getGet(),
            'post' => $pathItem->getPost(),
            'patch' => $pathItem->getPatch(),
        } ?? throw new \LogicException(\sprintf('The OpenAPI document has no %s operation.', strtoupper($method)));
    }

    private function withOperation(PathItem $pathItem, string $method, Operation $operation): PathItem
    {
        return match ($method) {
            'get' => $pathItem->withGet($operation),
            'post' => $pathItem->withPost($operation),
            'patch' => $pathItem->withPatch($operation),
        };
    }

    /**
     * @return array<string, array<string, array{summary: string, responses: array<int, Response>, requestBody?: \ApiPlatform\OpenApi\Model\RequestBody}>>
     */
    private static function operations(): array
    {
        return [
            '/books' => [
                'get' => [
                    'summary' => 'List published books',
                    'responses' => [
                        200 => JsonApiDocs::document('The books of the requested page.', JsonApiDocs::collectionDocument('books')),
                        400 => JsonApiDocs::error('An invalid query parameter.'),
                    ],
                ],
            ],
            '/books/{id}' => [
                'get' => [
                    'summary' => 'Get a published book',
                    'responses' => [
                        200 => JsonApiDocs::document('The book.', JsonApiDocs::singleDocument('books')),
                        400 => JsonApiDocs::error('An invalid include parameter.'),
                        404 => JsonApiDocs::error('No published book has this id.'),
                    ],
                ],
                'patch' => [
                    'summary' => 'Update the title, price or stock of a book, published or not',
                    'requestBody' => JsonApiDocs::body('The attributes to change; the others stay as they are.', [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => ['data' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['books']],
                                'id' => ['type' => 'string'],
                                'attributes' => ['type' => 'object', 'properties' => [
                                    'title' => ['type' => 'string'],
                                    'priceCents' => ['type' => 'integer', 'minimum' => 0],
                                    'stock' => ['type' => 'integer', 'minimum' => 0],
                                ]],
                            ],
                        ]],
                    ]),
                    'responses' => [
                        200 => JsonApiDocs::document('The updated book, when it is published.', JsonApiDocs::singleDocument('books')),
                        204 => JsonApiDocs::noContent('The book was updated and is not published.'),
                        400 => JsonApiDocs::error('The body is not a JSON:API document.'),
                        404 => JsonApiDocs::error('No book has this id.'),
                        409 => JsonApiDocs::error('The id or type of the body does not match the URL.'),
                        415 => JsonApiDocs::error('The Content-Type is not application/vnd.api+json.'),
                        422 => JsonApiDocs::error('An attribute is invalid; nothing was changed.'),
                    ],
                ],
            ],
            '/authors' => [
                'get' => [
                    'summary' => 'List authors',
                    'responses' => [
                        200 => JsonApiDocs::document('The authors of the requested page.', JsonApiDocs::collectionDocument('authors')),
                        400 => JsonApiDocs::error('An invalid query parameter.'),
                    ],
                ],
            ],
            '/authors/{id}' => [
                'get' => [
                    'summary' => 'Get an author with the list of their published books',
                    'responses' => [
                        200 => JsonApiDocs::document('The author.', JsonApiDocs::singleDocument('authors')),
                        400 => JsonApiDocs::error('An invalid include parameter.'),
                        404 => JsonApiDocs::error('No author has this id.'),
                    ],
                ],
            ],
            '/purchases' => [
                'get' => [
                    'summary' => 'List the purchases of the customer, most recent first',
                    'required' => ['Customer-Id'],
                    'responses' => [
                        200 => JsonApiDocs::document('The purchases of the requested page.', JsonApiDocs::collectionDocument('purchases')),
                        400 => JsonApiDocs::error('An invalid query parameter.'),
                        401 => JsonApiDocs::error('The Customer-Id header is missing, malformed or unknown.'),
                    ],
                ],
                'post' => [
                    'summary' => 'Buy books',
                    'required' => ['Customer-Id'],
                    'requestBody' => JsonApiDocs::body('The lines of the purchase.', [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => ['data' => [
                            'type' => 'object',
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['purchases']],
                                'attributes' => ['type' => 'object', 'required' => ['lines'], 'properties' => [
                                    'lines' => ['type' => 'array', 'minItems' => 1, 'items' => [
                                        'type' => 'object',
                                        'required' => ['bookId', 'quantity'],
                                        'properties' => [
                                            'bookId' => ['type' => 'integer'],
                                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                                        ],
                                    ]],
                                ]],
                            ],
                        ]],
                    ]),
                    'responses' => [
                        201 => JsonApiDocs::document('The purchase; its URL is in the Location header.', JsonApiDocs::singleDocument('purchases')),
                        401 => JsonApiDocs::error('The Customer-Id header is missing, malformed or unknown.'),
                        402 => JsonApiDocs::error('The payment was declined.'),
                        409 => JsonApiDocs::error('Not enough stock for the quantity asked.'),
                        422 => JsonApiDocs::error('A line is invalid or references an unavailable book.'),
                        502 => JsonApiDocs::error('The payment service is unreachable or failed.'),
                    ],
                ],
            ],
            '/purchases/{id}' => [
                'get' => [
                    'summary' => 'Get one of the purchases of the customer',
                    'required' => ['Customer-Id'],
                    'responses' => [
                        200 => JsonApiDocs::document('The purchase.', JsonApiDocs::singleDocument('purchases')),
                        401 => JsonApiDocs::error('The Customer-Id header is missing, malformed or unknown.'),
                        404 => JsonApiDocs::error('No purchase of this customer has this id.'),
                    ],
                ],
            ],
            '/reports/sales' => [
                'get' => [
                    'summary' => 'Download the sales report of a period as CSV',
                    'required' => ['from', 'to'],
                    'responses' => [
                        200 => new Response(
                            description: 'CSV file with the columns title, quantity and revenueCents, sorted by revenueCents descending then title.',
                            content: new \ArrayObject(['text/csv' => ['schema' => ['type' => 'string']]]),
                        ),
                        400 => JsonApiDocs::error('A missing, malformed or inverted date range.'),
                    ],
                ],
            ],
        ];
    }
}
