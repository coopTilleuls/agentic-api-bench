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

/**
 * Completes the generated OpenAPI document with the parameters, request bodies and error responses
 * of the bookshop: every filter, sort, include and page parameter, the Customer-Id header and the
 * JSON:API error documents.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final class BookshopOpenApiFactory implements OpenApiFactoryInterface
{
    private const JSON_API = 'application/vnd.api+json';

    public function __construct(private readonly OpenApiFactoryInterface $inner)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->inner)($context);
        $paths = $openApi->getPaths();

        foreach ($this->operations() as $path => $methods) {
            $pathItem = $paths->getPath($path);
            if (null === $pathItem) {
                continue;
            }
            foreach ($methods as $method => $spec) {
                $generated = $this->generated($pathItem, $method);
                if (null === $generated) {
                    continue;
                }
                $pathItem = $this->with($pathItem, $method, $this->complete($generated, $spec));
            }
            $paths->addPath($path, $pathItem);
        }

        return $openApi;
    }

    private function generated(PathItem $pathItem, string $method): ?Operation
    {
        return match ($method) {
            'get' => $pathItem->getGet(),
            'post' => $pathItem->getPost(),
            'patch' => $pathItem->getPatch(),
        };
    }

    private function with(PathItem $pathItem, string $method, Operation $operation): PathItem
    {
        return match ($method) {
            'get' => $pathItem->withGet($operation),
            'post' => $pathItem->withPost($operation),
            'patch' => $pathItem->withPatch($operation),
        };
    }

    /**
     * @param array{parameters: list<Parameter>, responses: array<int|string, Response>, body?: RequestBody} $spec
     */
    private function complete(Operation $generated, array $spec): Operation
    {
        // Keep the path parameters API Platform derived from the URI template and the success responses it generated.
        $parameters = array_values(array_filter(
            $generated->getParameters() ?? [],
            static fn (Parameter $parameter) => 'path' === $parameter->getIn(),
        ));
        $responses = array_intersect_key($generated->getResponses() ?? [], array_flip(['200', '201', '204']));

        $operation = $generated
            ->withParameters(array_merge($parameters, $spec['parameters']))
            ->withResponses($responses + $spec['responses']);

        return isset($spec['body']) ? $operation->withRequestBody($spec['body']) : $operation;
    }

    /**
     * @return array<string, array<string, array{parameters: list<Parameter>, responses: array<int|string, Response>, body?: RequestBody}>>
     */
    private function operations(): array
    {
        $page = [
            new Parameter('page[number]', 'query', 'The page to return, from 1.', false, false, null, ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            new Parameter('page[size]', 'query', 'The number of items per page, from 1 to 50.', false, false, null, ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
        ];
        $customerId = new Parameter('Customer-Id', 'header', 'The id of the customer making the request.', true, false, null, ['type' => 'string', 'pattern' => '^[1-9][0-9]{0,8}$']);
        $bookInclude = new Parameter('include', 'query', 'Relationships to include in the compound document. Allowed: author.', false, false, null, ['type' => 'string', 'enum' => ['author']]);
        $sort = new Parameter('sort', 'query', 'Comma separated fields, ascending unless prefixed with "-", applied in order, ties broken by id. Allowed: priceCents, title, publishedAt.', false, false, null, ['type' => 'string', 'example' => '-priceCents,title']);
        $bookFilters = [
            new Parameter('filter[title]', 'query', 'Books whose title contains the text, case-insensitive.', false, false, null, ['type' => 'string']),
            new Parameter('filter[author]', 'query', 'Books whose author has exactly this name.', false, false, null, ['type' => 'string']),
            new Parameter('filter[minPrice]', 'query', 'Price in cents, lower bound included.', false, false, null, ['type' => 'integer']),
            new Parameter('filter[maxPrice]', 'query', 'Price in cents, upper bound included.', false, false, null, ['type' => 'integer']),
            new Parameter('filter[publishedAfter]', 'query', 'Published on or after this date.', false, false, null, ['type' => 'string', 'format' => 'date']),
            new Parameter('filter[publishedBefore]', 'query', 'Published on or before this date.', false, false, null, ['type' => 'string', 'format' => 'date']),
            new Parameter('filter[inStock]', 'query', 'true: stock greater than 0; false: stock equal to 0.', false, false, null, ['type' => 'boolean']),
        ];
        $authorInclude = new Parameter('include', 'query', 'Relationships to include in the compound document. Allowed: books.', false, false, null, ['type' => 'string', 'enum' => ['books']]);

        $badRequest = $this->error('The query parameters or the request document are invalid.', 400);
        $notFound = $this->error('No such resource.', 404);
        $unauthorized = $this->error('The Customer-Id header is missing, malformed or names an unknown customer.', 401);

        return [
            '/authors' => [
                'get' => ['parameters' => [...$page, $authorInclude], 'responses' => [400 => $badRequest]],
            ],
            '/authors/{id}' => [
                'get' => ['parameters' => [$authorInclude], 'responses' => [400 => $badRequest, 404 => $notFound]],
            ],
            '/books' => [
                'get' => ['parameters' => [...$page, $sort, $bookInclude, ...$bookFilters], 'responses' => [400 => $badRequest]],
            ],
            '/books/{id}' => [
                'get' => ['parameters' => [$bookInclude], 'responses' => [400 => $badRequest, 404 => $notFound]],
                'patch' => [
                    'parameters' => [],
                    'body' => new RequestBody(
                        'The book attributes to change; only the attributes sent change.',
                        new \ArrayObject([self::JSON_API => ['schema' => $this->bookPatchSchema()]]),
                        true,
                    ),
                    'responses' => [
                        204 => new Response('The book is unpublished: the change is stored and there is no document.'),
                        400 => $badRequest,
                        404 => $notFound,
                        409 => $this->error('The data id or type does not match the path or the resource.', 409),
                        415 => $this->error('The Content-Type is not application/vnd.api+json.', 415),
                        422 => $this->error('An attribute is invalid: title must be a non-empty string, priceCents and stock integers of at least 0.', 422),
                    ],
                ],
            ],
            '/purchases' => [
                'get' => ['parameters' => [$customerId, ...$page], 'responses' => [400 => $badRequest, 401 => $unauthorized]],
                'post' => [
                    'parameters' => [$customerId],
                    'body' => new RequestBody(
                        'The lines of the purchase.',
                        new \ArrayObject([self::JSON_API => ['schema' => $this->purchaseSchema()]]),
                        true,
                    ),
                    'responses' => [
                        400 => $badRequest,
                        401 => $unauthorized,
                        402 => $this->error('The payment was declined.', 402),
                        409 => $this->error('A book has not enough copies in stock for the total quantity asked.', 409),
                        415 => $this->error('The Content-Type is not application/vnd.api+json.', 415),
                        422 => $this->error('A line is invalid, or references a book that is unpublished or does not exist.', 422),
                        502 => $this->error('The payment service is unreachable, fails, or answers something unexpected.', 502),
                    ],
                ],
            ],
            '/purchases/{id}' => [
                'get' => ['parameters' => [$customerId], 'responses' => [401 => $unauthorized, 404 => $notFound]],
            ],
            '/reports/sales' => [
                'get' => [
                    'parameters' => [
                        new Parameter('from', 'query', 'First day of the report, included (UTC).', true, false, null, ['type' => 'string', 'format' => 'date']),
                        new Parameter('to', 'query', 'Last day of the report, included (UTC).', true, false, null, ['type' => 'string', 'format' => 'date']),
                    ],
                    'responses' => [
                        200 => new Response('A CSV file: title, quantity, revenueCents; one line per book sold, by revenueCents descending, then title, then id.', new \ArrayObject([
                            'text/csv' => ['schema' => ['type' => 'string']],
                        ])),
                        400 => $badRequest,
                    ],
                ],
            ],
        ];
    }

    private function error(string $description, int $status): Response
    {
        return new Response($description, new \ArrayObject([
            self::JSON_API => ['schema' => [
                'type' => 'object',
                'required' => ['errors'],
                'properties' => ['errors' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['status', 'title', 'detail'],
                    'properties' => [
                        'status' => ['type' => 'string', 'example' => (string) $status],
                        'title' => ['type' => 'string'],
                        'detail' => ['type' => 'string'],
                        'source' => ['type' => 'object', 'properties' => [
                            'pointer' => ['type' => 'string', 'example' => '/data/attributes/priceCents'],
                            'parameter' => ['type' => 'string', 'example' => 'sort'],
                        ]],
                    ],
                ]]],
            ]],
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function bookPatchSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => ['data' => [
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
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => ['data' => [
                'type' => 'object',
                'required' => ['type', 'attributes'],
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['purchases']],
                    'attributes' => [
                        'type' => 'object',
                        'required' => ['lines'],
                        'properties' => ['lines' => [
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
                        ]],
                    ],
                ],
            ]],
        ];
    }
}
