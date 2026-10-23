<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Describes the operations of the bookshop (parameters, bodies and responses) in the OpenAPI document.
 */
#[AsDecorator('api_platform.openapi.factory')]
final class BookshopOpenApiFactory implements OpenApiFactoryInterface
{
    private const MAX_INTEGER = 2147483647;

    public function __construct(private readonly OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        foreach ($this->operations() as $path => $methods) {
            $pathItem = $paths->getPath($path) ?? new PathItem();

            foreach ($methods as $method => $operation) {
                $pathItem = match ($method) {
                    'get' => $pathItem->withGet($operation),
                    'post' => $pathItem->withPost($operation),
                    'patch' => $pathItem->withPatch($operation),
                };
            }

            $paths->addPath($path, $pathItem);
        }

        return $openApi->withPaths($paths)->withOpenapi('3.1.0');
    }

    /**
     * @return array<string, array<'get'|'post'|'patch', Operation>>
     */
    private function operations(): array
    {
        $bookAttributes = [
            'title' => ['type' => 'string'],
            'priceCents' => ['type' => 'integer'],
            'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date'],
            'stock' => ['type' => 'integer'],
        ];
        $authorAttributes = [
            'name' => ['type' => ['string', 'null']],
            'publishedBooks' => ['type' => 'integer'],
        ];
        $purchaseAttributes = [
            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            'totalCents' => ['type' => 'integer'],
            'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                'bookId' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
                'unitPriceCents' => ['type' => 'integer'],
            ]]],
        ];

        return [
            '/authors' => [
                'get' => new Operation(
                    operationId: 'listAuthors',
                    summary: 'List the authors, in id order.',
                    parameters: [Spec::pageNumber(), Spec::pageSize(), Spec::include('books')],
                    responses: [
                        '200' => Spec::document('The authors of the page.', Spec::resource('authors', $authorAttributes, ['books' => Spec::relationship('books')]), collection: true, included: true),
                        '400' => Spec::errors('Invalid query parameter.'),
                    ],
                ),
            ],
            '/authors/{id}' => [
                'get' => new Operation(
                    operationId: 'getAuthor',
                    summary: 'Get one author, with the published books as a relationship.',
                    parameters: [Spec::pathId(), Spec::include('books')],
                    responses: [
                        '200' => Spec::document('The author.', Spec::resource('authors', $authorAttributes, ['books' => Spec::relationship('books')]), included: true),
                        '400' => Spec::errors('Invalid include path.'),
                        '404' => Spec::errors('Unknown or malformed author id.'),
                    ],
                ),
            ],
            '/books' => [
                'get' => new Operation(
                    operationId: 'listBooks',
                    summary: 'List the published books.',
                    parameters: [
                        Spec::pageNumber(),
                        Spec::pageSize(),
                        Spec::include('author'),
                        Spec::sort('priceCents, title, publishedAt'),
                        Spec::filter('title', 'Title contains the text, case-insensitive.', ['type' => 'string']),
                        Spec::filter('author', 'Author name, exact.', ['type' => 'string']),
                        Spec::filter('minPrice', 'Minimum price in cents, included.', ['type' => 'integer']),
                        Spec::filter('maxPrice', 'Maximum price in cents, included.', ['type' => 'integer']),
                        Spec::filter('publishedAfter', 'Published on or after this date.', ['type' => 'string', 'format' => 'date']),
                        Spec::filter('publishedBefore', 'Published on or before this date.', ['type' => 'string', 'format' => 'date']),
                        Spec::filter('inStock', 'true: stock above 0; false: stock 0.', ['type' => 'boolean']),
                    ],
                    responses: [
                        '200' => Spec::document('The books of the page.', Spec::resource('books', $bookAttributes, ['author' => Spec::relationship('authors')]), collection: true, included: true),
                        '400' => Spec::errors('Invalid query parameter.'),
                    ],
                ),
            ],
            '/books/{id}' => [
                'get' => new Operation(
                    operationId: 'getBook',
                    summary: 'Get one published book.',
                    parameters: [Spec::pathId(), Spec::include('author')],
                    responses: [
                        '200' => Spec::document('The book.', Spec::resource('books', $bookAttributes, ['author' => Spec::relationship('authors')]), included: true),
                        '400' => Spec::errors('Invalid include path.'),
                        '404' => Spec::errors('Unknown, unpublished or malformed book id.'),
                    ],
                ),
                'patch' => new Operation(
                    operationId: 'updateBook',
                    summary: 'Update the title, price and stock of a book, published or not. Only the attributes sent change.',
                    parameters: [Spec::pathId()],
                    requestBody: Spec::body('The book with the attributes to change.', [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => ['data' => Spec::resource('books', [
                            'title' => ['type' => 'string', 'minLength' => 1],
                            'priceCents' => ['type' => 'integer', 'minimum' => 0, 'maximum' => self::MAX_INTEGER],
                            'stock' => ['type' => 'integer', 'minimum' => 0, 'maximum' => self::MAX_INTEGER],
                        ])],
                    ]),
                    responses: [
                        '200' => Spec::document('The updated book, when it is published.', Spec::resource('books', $bookAttributes, ['author' => Spec::relationship('authors')])),
                        '204' => Spec::noContent('The book was updated and is not published.'),
                        '404' => Spec::errors('Unknown or malformed book id.'),
                        '409' => Spec::errors('The id in the body does not match the path.'),
                        '415' => Spec::errors('The Content-Type is not application/vnd.api+json.'),
                        '422' => Spec::errors('Invalid attributes; nothing is changed.'),
                    ],
                ),
            ],
            '/purchases' => [
                'get' => new Operation(
                    operationId: 'listPurchases',
                    summary: 'List the purchases of the customer, most recent first.',
                    parameters: [Spec::customerId(), Spec::pageNumber(), Spec::pageSize()],
                    responses: [
                        '200' => Spec::document('The purchases of the page.', Spec::resource('purchases', $purchaseAttributes), collection: true),
                        '400' => Spec::errors('Invalid query parameter.'),
                        '401' => Spec::errors('Missing, unknown or malformed Customer-Id.'),
                    ],
                ),
                'post' => new Operation(
                    operationId: 'createPurchase',
                    summary: 'Buy books: stores the purchase, its lines, and decreases the stock.',
                    parameters: [Spec::customerId()],
                    requestBody: Spec::body('The lines to buy.', [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => ['data' => [
                            'type' => 'object',
                            'required' => ['type', 'attributes'],
                            'properties' => [
                                'type' => ['type' => 'string', 'enum' => ['purchases']],
                                'attributes' => ['type' => 'object', 'required' => ['lines'], 'properties' => [
                                    'lines' => ['type' => 'array', 'minItems' => 1, 'items' => [
                                        'type' => 'object',
                                        'required' => ['bookId', 'quantity'],
                                        'properties' => [
                                            'bookId' => ['type' => 'integer', 'minimum' => 1],
                                            'quantity' => ['type' => 'integer', 'minimum' => 1],
                                        ],
                                    ]],
                                ]],
                            ],
                        ]],
                    ]),
                    responses: [
                        '201' => Spec::document('The purchase, with a Location header holding its URL.', Spec::resource('purchases', $purchaseAttributes)),
                        '401' => Spec::errors('Missing, unknown or malformed Customer-Id.'),
                        '402' => Spec::errors('The payment was declined.'),
                        '409' => Spec::errors('Not enough stock.'),
                        '415' => Spec::errors('The Content-Type is not application/vnd.api+json.'),
                        '422' => Spec::errors('Invalid lines, or a book that does not exist or is not published.'),
                        '502' => Spec::errors('The payment service failed or is unreachable.'),
                    ],
                ),
            ],
            '/purchases/{id}' => [
                'get' => new Operation(
                    operationId: 'getPurchase',
                    summary: 'Get one purchase of the customer.',
                    parameters: [Spec::pathId(), Spec::customerId()],
                    responses: [
                        '200' => Spec::document('The purchase.', Spec::resource('purchases', $purchaseAttributes)),
                        '401' => Spec::errors('Missing, unknown or malformed Customer-Id.'),
                        '404' => Spec::errors('Unknown purchase, or a purchase of another customer.'),
                    ],
                ),
            ],
            '/reports/sales' => [
                'get' => new Operation(
                    operationId: 'getSalesReport',
                    summary: 'Download a CSV report of the books sold between two days, for all customers.',
                    parameters: [
                        Spec::query('from', 'First day of the report, included (UTC).', ['type' => 'string', 'format' => 'date']),
                        Spec::query('to', 'Last day of the report, included (UTC).', ['type' => 'string', 'format' => 'date']),
                    ],
                    responses: [
                        '200' => Spec::csv('The CSV file: title, quantity, revenueCents; one line per book sold, by revenue descending, then title, then id.'),
                        '400' => Spec::errors('Missing, malformed or inverted from/to.'),
                    ],
                ),
            ],
        ];
    }
}
