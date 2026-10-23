<?php

namespace App\OpenApi;

/**
 * The OpenAPI 3.0 description of the bookshop API.
 */
final class Specification
{
    /**
     * @return array<string, mixed>
     */
    public static function document(): array
    {
        $jsonApi = 'application/vnd.api+json';
        $ref = static fn (string $kind, string $name): array => ['$ref' => "#/components/$kind/$name"];
        $errorResponse = static fn (string $description): array => [
            'description' => $description,
            'content' => [$jsonApi => ['schema' => $ref('schemas', 'ErrorDocument')]],
        ];
        $listResponse = static fn (string $schema): array => [
            'description' => 'A page of results.',
            'content' => [$jsonApi => ['schema' => ['$ref' => "#/components/schemas/$schema"]]],
        ];
        $itemResponse = static fn (string $schema, string $description = 'The resource.'): array => [
            'description' => $description,
            'content' => [$jsonApi => ['schema' => ['$ref' => "#/components/schemas/$schema"]]],
        ];
        $customerHeader = ['$ref' => '#/components/parameters/CustomerId'];
        $pageParams = [['$ref' => '#/components/parameters/PageNumber'], ['$ref' => '#/components/parameters/PageSize']];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Bookshop API',
                'version' => '1.0.0',
                'description' => 'JSON:API 1.1 bookshop. Every response except the CSV report and 204 is an application/vnd.api+json document.',
            ],
            'servers' => [['url' => 'http://localhost:8089']],
            'paths' => [
                '/authors' => [
                    'get' => [
                        'operationId' => 'listAuthors',
                        'summary' => 'List authors',
                        'parameters' => [...$pageParams, ['$ref' => '#/components/parameters/AuthorInclude']],
                        'responses' => [
                            '200' => $listResponse('AuthorList'),
                            '400' => $errorResponse('Invalid query parameter.'),
                        ],
                    ],
                ],
                '/authors/{id}' => [
                    'get' => [
                        'operationId' => 'showAuthor',
                        'summary' => 'Show an author with the list of published books',
                        'parameters' => [['$ref' => '#/components/parameters/Id'], ['$ref' => '#/components/parameters/AuthorInclude']],
                        'responses' => [
                            '200' => $itemResponse('AuthorDocument'),
                            '400' => $errorResponse('Invalid query parameter.'),
                            '404' => $errorResponse('Unknown author.'),
                        ],
                    ],
                ],
                '/books' => [
                    'get' => [
                        'operationId' => 'listBooks',
                        'summary' => 'List published books',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/FilterTitle'],
                            ['$ref' => '#/components/parameters/FilterAuthor'],
                            ['$ref' => '#/components/parameters/FilterMinPrice'],
                            ['$ref' => '#/components/parameters/FilterMaxPrice'],
                            ['$ref' => '#/components/parameters/FilterPublishedAfter'],
                            ['$ref' => '#/components/parameters/FilterPublishedBefore'],
                            ['$ref' => '#/components/parameters/FilterInStock'],
                            ['$ref' => '#/components/parameters/BookSort'],
                            ...$pageParams,
                            ['$ref' => '#/components/parameters/BookInclude'],
                        ],
                        'responses' => [
                            '200' => $listResponse('BookList'),
                            '400' => $errorResponse('Invalid query parameter.'),
                        ],
                    ],
                ],
                '/books/{id}' => [
                    'get' => [
                        'operationId' => 'showBook',
                        'summary' => 'Show a published book',
                        'parameters' => [['$ref' => '#/components/parameters/Id'], ['$ref' => '#/components/parameters/BookInclude']],
                        'responses' => [
                            '200' => $itemResponse('BookDocument'),
                            '400' => $errorResponse('Invalid query parameter.'),
                            '404' => $errorResponse('Unknown or unpublished book.'),
                        ],
                    ],
                    'patch' => [
                        'operationId' => 'updateBook',
                        'summary' => 'Partially update a book, published or not',
                        'parameters' => [['$ref' => '#/components/parameters/Id']],
                        'requestBody' => [
                            'required' => true,
                            'content' => [$jsonApi => ['schema' => $ref('schemas', 'BookUpdateDocument')]],
                        ],
                        'responses' => [
                            '200' => $itemResponse('BookDocument', 'The updated book.'),
                            '204' => ['description' => 'The book is unpublished, so it has no document.'],
                            '404' => $errorResponse('Unknown book.'),
                            '409' => $errorResponse('The id or type in the body does not match the path.'),
                            '415' => $errorResponse('Content-Type is not application/vnd.api+json.'),
                            '422' => $errorResponse('Invalid attributes; nothing is changed.'),
                        ],
                    ],
                ],
                '/purchases' => [
                    'get' => [
                        'operationId' => 'listPurchases',
                        'summary' => 'List the purchases of the customer, most recent first',
                        'parameters' => [$customerHeader, ...$pageParams],
                        'responses' => [
                            '200' => $listResponse('PurchaseList'),
                            '400' => $errorResponse('Invalid query parameter.'),
                            '401' => $errorResponse('Missing or unknown Customer-Id.'),
                        ],
                    ],
                    'post' => [
                        'operationId' => 'createPurchase',
                        'summary' => 'Buy books',
                        'parameters' => [$customerHeader],
                        'requestBody' => [
                            'required' => true,
                            'content' => [$jsonApi => ['schema' => $ref('schemas', 'PurchaseCreateDocument')]],
                        ],
                        'responses' => [
                            '201' => $itemResponse('PurchaseDocument', 'The purchase, with a Location header.'),
                            '400' => $errorResponse('The body is not valid JSON.'),
                            '401' => $errorResponse('Missing or unknown Customer-Id.'),
                            '402' => $errorResponse('The payment was declined.'),
                            '409' => $errorResponse('Not enough stock.'),
                            '415' => $errorResponse('Content-Type is not application/vnd.api+json.'),
                            '422' => $errorResponse('Invalid lines, or a book that is not published.'),
                            '502' => $errorResponse('The payment service failed.'),
                        ],
                    ],
                ],
                '/purchases/{id}' => [
                    'get' => [
                        'operationId' => 'showPurchase',
                        'summary' => 'Show one of the purchases of the customer',
                        'parameters' => [['$ref' => '#/components/parameters/Id'], $customerHeader],
                        'responses' => [
                            '200' => $itemResponse('PurchaseDocument'),
                            '401' => $errorResponse('Missing or unknown Customer-Id.'),
                            '404' => $errorResponse('Unknown purchase, or another customer\'s purchase.'),
                        ],
                    ],
                ],
                '/reports/sales' => [
                    'get' => [
                        'operationId' => 'salesReport',
                        'summary' => 'Books sold between two dates, as CSV (public)',
                        'parameters' => [
                            ['$ref' => '#/components/parameters/From'],
                            ['$ref' => '#/components/parameters/To'],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'CSV file with columns title, quantity, revenueCents.',
                                'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
                            ],
                            '400' => $errorResponse('Missing, malformed or reversed dates.'),
                        ],
                    ],
                ],
                '/openapi.json' => [
                    'get' => [
                        'operationId' => 'openapi',
                        'summary' => 'This OpenAPI document',
                        'responses' => [
                            '200' => ['description' => 'The OpenAPI document.', 'content' => [$jsonApi => ['schema' => ['type' => 'object']]]],
                        ],
                    ],
                ],
            ],
            'components' => [
                'parameters' => self::parameters(),
                'schemas' => self::schemas(),
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function parameters(): array
    {
        $query = static fn (string $name, string $description, array $schema): array => [
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'schema' => $schema,
        ];

        return [
            'Id' => [
                'name' => 'id',
                'in' => 'path',
                'required' => true,
                'description' => 'Database id.',
                'schema' => ['type' => 'string', 'pattern' => '^\d+$'],
            ],
            'CustomerId' => [
                'name' => 'Customer-Id',
                'in' => 'header',
                'required' => true,
                'description' => 'Id of the customer; unknown or missing answers 401.',
                'schema' => ['type' => 'string', 'pattern' => '^\d+$'],
            ],
            'PageNumber' => $query('page[number]', 'Page number, from 1.', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            'PageSize' => $query('page[size]', 'Items per page, 1 to 50.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
            'AuthorInclude' => $query('include', 'Related resources to include: books.', ['type' => 'string', 'enum' => ['books']]),
            'BookInclude' => $query('include', 'Related resources to include: author.', ['type' => 'string', 'enum' => ['author']]),
            'BookSort' => $query('sort', 'Comma separated fields among priceCents, title, publishedAt; prefix - for descending. Ties broken by id ascending.', ['type' => 'string', 'example' => '-priceCents,title']),
            'FilterTitle' => $query('filter[title]', 'Title contains the text, case-insensitive.', ['type' => 'string']),
            'FilterAuthor' => $query('filter[author]', 'Author name, exact.', ['type' => 'string']),
            'FilterMinPrice' => $query('filter[minPrice]', 'Minimum price in cents, inclusive.', ['type' => 'integer']),
            'FilterMaxPrice' => $query('filter[maxPrice]', 'Maximum price in cents, inclusive.', ['type' => 'integer']),
            'FilterPublishedAfter' => $query('filter[publishedAfter]', 'Published on or after this date.', ['type' => 'string', 'format' => 'date']),
            'FilterPublishedBefore' => $query('filter[publishedBefore]', 'Published on or before this date.', ['type' => 'string', 'format' => 'date']),
            'FilterInStock' => $query('filter[inStock]', 'true: stock above 0; false: stock 0.', ['type' => 'boolean']),
            'From' => $query('from', 'First day, inclusive (YYYY-MM-DD, UTC).', ['type' => 'string', 'format' => 'date']),
            'To' => $query('to', 'Last day, inclusive (YYYY-MM-DD, UTC).', ['type' => 'string', 'format' => 'date']),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function schemas(): array
    {
        $links = ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]];
        $identifier = static fn (string $type): array => [
            'type' => 'object',
            'properties' => ['type' => ['type' => 'string', 'enum' => [$type]], 'id' => ['type' => 'string']],
            'required' => ['type', 'id'],
        ];
        $resource = static fn (string $type, array $attributes, array $relationships = []): array => [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => [$type]],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => $attributes],
                'relationships' => ['type' => 'object', 'properties' => $relationships],
                'links' => $links,
            ],
        ];
        $pageLinks = [
            'type' => 'object',
            'properties' => [
                'self' => ['type' => 'string'],
                'first' => ['type' => 'string'],
                'last' => ['type' => 'string'],
                'prev' => ['type' => 'string', 'nullable' => true],
                'next' => ['type' => 'string', 'nullable' => true],
            ],
        ];
        $list = static fn (string $itemSchema): array => [
            'type' => 'object',
            'required' => ['data', 'links', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/$itemSchema"]],
                'included' => ['type' => 'array', 'items' => ['type' => 'object']],
                'links' => $pageLinks,
                'meta' => ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']]],
            ],
        ];
        $single = static fn (string $itemSchema): array => [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => ['$ref' => "#/components/schemas/$itemSchema"],
                'included' => ['type' => 'array', 'items' => ['type' => 'object']],
                'links' => $links,
            ],
        ];
        $line = [
            'type' => 'object',
            'properties' => [
                'bookId' => ['type' => 'integer'],
                'title' => ['type' => 'string'],
                'quantity' => ['type' => 'integer'],
                'unitPriceCents' => ['type' => 'integer'],
            ],
        ];

        return [
            'ErrorDocument' => [
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
                                'source' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'pointer' => ['type' => 'string'],
                                        'parameter' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'Book' => $resource('books', [
                'title' => ['type' => 'string'],
                'priceCents' => ['type' => 'integer'],
                'publishedAt' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                'stock' => ['type' => 'integer'],
            ], [
                'author' => ['type' => 'object', 'properties' => ['data' => $identifier('authors')]],
            ]),
            'Author' => $resource('authors', [
                'name' => ['type' => 'string', 'nullable' => true],
                'publishedBooks' => ['type' => 'integer'],
            ], [
                'books' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => $identifier('books')]]],
            ]),
            'Purchase' => $resource('purchases', [
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                'totalCents' => ['type' => 'integer'],
                'lines' => ['type' => 'array', 'items' => $line],
            ]),
            'BookDocument' => $single('Book'),
            'BookList' => $list('Book'),
            'AuthorDocument' => $single('Author'),
            'AuthorList' => $list('Author'),
            'PurchaseDocument' => $single('Purchase'),
            'PurchaseList' => $list('Purchase'),
            'BookUpdateDocument' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['type'],
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
            ],
            'PurchaseCreateDocument' => [
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
            ],
        ];
    }
}
