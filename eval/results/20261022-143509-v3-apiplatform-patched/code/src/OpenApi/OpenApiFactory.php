<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Info;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Paths;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Completes the API Platform generated document: the title, and the responses and request bodies of each operation.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final class OpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(private readonly OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $paths = new Paths();
        foreach ($openApi->getPaths()->getPaths() as $path => $pathItem) {
            $paths->addPath($path, $this->describe($path, $pathItem));
        }

        return $openApi
            ->withInfo(new Info('Bookshop API', '1.0.0', 'Bookshop JSON:API: books, authors and purchases, with a sales report.'))
            ->withPaths($paths);
    }

    private function describe(string $path, PathItem $item): PathItem
    {
        $error = Schemas::error();

        return match ($path) {
            '/books' => $item->withGet($this->operation($item->getGet(), 'List books', [
                '200' => $this->json('Published books on the requested page.', Schemas::collection(Schemas::book(), true)),
                '400' => $this->json('Invalid query parameter.', $error),
            ])),
            '/books/{id}' => $item
                ->withGet($this->operation($item->getGet(), 'Get a published book', [
                    '200' => $this->json('The book.', Schemas::single(Schemas::book(), true)),
                    '400' => $this->json('Invalid query parameter.', $error),
                    '404' => $this->json('Unknown or unpublished book.', $error),
                ]))
                ->withPatch($this->operation($item->getPatch(), 'Update a book: title, priceCents and stock. Also works on unpublished books.', [
                    '200' => $this->json('The updated book (published books).', Schemas::single(Schemas::book(), false)),
                    '204' => new Response(description: 'The updated book is unpublished; no content.'),
                    '404' => $this->json('Unknown book.', $error),
                    '409' => $this->json('The type or id in the body does not match the URL.', $error),
                    '415' => $this->json('Content-Type is not application/vnd.api+json.', $error),
                    '422' => $this->json('Invalid attribute; one error per invalid field.', $error),
                ], $this->body(Schemas::single([
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
                ], false)))),
            '/authors' => $item->withGet($this->operation($item->getGet(), 'List authors with their published book count', [
                '200' => $this->json('Authors on the requested page.', Schemas::collection(Schemas::author(), true)),
                '400' => $this->json('Invalid query parameter.', $error),
            ])),
            '/authors/{id}' => $item->withGet($this->operation($item->getGet(), 'Get an author with their published books', [
                '200' => $this->json('The author.', Schemas::single(Schemas::author(), true)),
                '400' => $this->json('Invalid query parameter.', $error),
                '404' => $this->json('Unknown author.', $error),
            ])),
            '/purchases' => $item
                ->withGet($this->operation($item->getGet(), 'List the customer\'s purchases, most recent first', [
                    '200' => $this->json('The purchases on the requested page.', Schemas::collection(Schemas::purchase(), false)),
                    '401' => $this->json('Missing, malformed or unknown Customer-Id.', $error),
                ]))
                ->withPost($this->operation($item->getPost(), 'Buy books', [
                    '201' => new Response(
                        description: 'The purchase. The Location header holds its URL.',
                        content: $this->content(Schemas::single(Schemas::purchase(), false)),
                    ),
                    '401' => $this->json('Missing, malformed or unknown Customer-Id.', $error),
                    '402' => $this->json('The payment was declined.', $error),
                    '409' => $this->json('Not enough stock for a book.', $error),
                    '415' => $this->json('Content-Type is not application/vnd.api+json.', $error),
                    '422' => $this->json('Invalid lines, or a book that is not published.', $error),
                    '502' => $this->json('The payment service failed or is unreachable.', $error),
                ], $this->body(Schemas::single([
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
                                            'bookId' => ['type' => 'integer'],
                                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ], false)))),
            '/purchases/{id}' => $item->withGet($this->operation($item->getGet(), 'Get one of the customer\'s purchases', [
                '200' => $this->json('The purchase.', Schemas::single(Schemas::purchase(), false)),
                '401' => $this->json('Missing, malformed or unknown Customer-Id.', $error),
                '404' => $this->json('Unknown purchase, or another customer\'s.', $error),
            ])),
            '/reports/sales' => $item->withGet($this->operation($item->getGet(), 'Sales report: books sold between two dates (UTC, inclusive), as CSV', [
                '200' => new Response(
                    description: 'CSV file with the columns title, quantity, revenueCents.',
                    content: new \ArrayObject(['text/csv' => new MediaType(schema: new \ArrayObject(['type' => 'string']))]),
                ),
                '400' => $this->json('Missing, malformed or reversed from/to.', $error),
            ])),
            default => $item,
        };
    }

    /**
     * @param array<string, Response> $responses
     */
    private function operation(?Operation $operation, string $summary, array $responses, ?RequestBody $body = null): Operation
    {
        $operation = ($operation ?? new Operation())->withSummary($summary)->withResponses($responses);

        return null === $body ? $operation : $operation->withRequestBody($body);
    }

    private function json(string $description, array $schema): Response
    {
        return new Response(description: $description, content: $this->content($schema));
    }

    private function content(array $schema): \ArrayObject
    {
        return new \ArrayObject([Schemas::JSON_API => new MediaType(schema: new \ArrayObject($schema))]);
    }

    private function body(array $schema): RequestBody
    {
        return new RequestBody(content: $this->content($schema), required: true);
    }
}
