<?php

namespace App\OpenApi;

/**
 * OpenAPI 3.0 description of the bookshop API.
 */
final class Specification
{
    private const JSONAPI = 'application/vnd.api+json';

    /** @return array<string, mixed> */
    public static function document(): array
    {
        $ref = static fn (string $name): array => ['$ref' => "#/components/parameters/$name"];
        $errors = static fn (string $description): array => [
            'description' => $description,
            'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/ErrorDocument']]],
        ];
        $document = static fn (string $description, string $schema): array => [
            'description' => $description,
            'content' => [self::JSONAPI => ['schema' => ['$ref' => "#/components/schemas/$schema"]]],
        ];
        $listParameters = [
            $ref('PageNumber'),
            $ref('PageSize'),
        ];
        $customer = [$ref('CustomerId')];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Bookshop API',
                'version' => '1.0.0',
                'description' => 'JSON:API 1.1 bookshop. Customers identify themselves with the Customer-Id header on purchases.',
            ],
            'servers' => [['url' => 'http://localhost:8089']],
            'paths' => [
                '/authors' => [
                    'get' => [
                        'operationId' => 'listAuthors',
                        'summary' => 'List authors',
                        'parameters' => [...$listParameters, $ref('AuthorSort'), $ref('AuthorInclude')],
                        'responses' => [
                            '200' => $document('Page of authors', 'AuthorList'),
                            '400' => $errors('Invalid query parameter'),
                        ],
                    ],
                ],
                '/authors/{id}' => [
                    'get' => [
                        'operationId' => 'getAuthor',
                        'summary' => 'Get one author with its published books',
                        'parameters' => [$ref('Id'), $ref('AuthorInclude')],
                        'responses' => [
                            '200' => $document('The author', 'AuthorDocument'),
                            '400' => $errors('Invalid include path'),
                            '404' => $errors('Unknown author'),
                        ],
                    ],
                ],
                '/books' => [
                    'get' => [
                        'operationId' => 'listBooks',
                        'summary' => 'List published books',
                        'parameters' => [
                            ...$listParameters,
                            $ref('BookSort'),
                            $ref('BookInclude'),
                            $ref('FilterTitle'),
                            $ref('FilterAuthor'),
                            $ref('FilterMinPrice'),
                            $ref('FilterMaxPrice'),
                            $ref('FilterPublishedAfter'),
                            $ref('FilterPublishedBefore'),
                            $ref('FilterInStock'),
                        ],
                        'responses' => [
                            '200' => $document('Page of books', 'BookList'),
                            '400' => $errors('Invalid query parameter'),
                        ],
                    ],
                ],
                '/books/{id}' => [
                    'get' => [
                        'operationId' => 'getBook',
                        'summary' => 'Get one published book',
                        'parameters' => [$ref('Id'), $ref('BookInclude')],
                        'responses' => [
                            '200' => $document('The book', 'BookDocument'),
                            '400' => $errors('Invalid include path'),
                            '404' => $errors('Unknown or unpublished book'),
                        ],
                    ],
                    'patch' => [
                        'operationId' => 'updateBook',
                        'summary' => 'Partially update a book, published or not',
                        'parameters' => [$ref('Id')],
                        'requestBody' => [
                            'required' => true,
                            'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/BookUpdateDocument']]],
                        ],
                        'responses' => [
                            '200' => $document('The updated book (published books)', 'BookDocument'),
                            '204' => ['description' => 'Book updated, but unpublished'],
                            '400' => $errors('Malformed document'),
                            '404' => $errors('Unknown book'),
                            '409' => $errors('data.id or data.type does not match the URL or resource'),
                            '415' => $errors('Content-Type is not application/vnd.api+json'),
                            '422' => $errors('Invalid attribute values, nothing changed'),
                        ],
                    ],
                ],
                '/purchases' => [
                    'get' => [
                        'operationId' => 'listPurchases',
                        'summary' => 'List the purchases of the customer, most recent first',
                        'parameters' => [...$customer, ...$listParameters, $ref('PurchaseSort')],
                        'responses' => [
                            '200' => $document('Page of purchases', 'PurchaseList'),
                            '400' => $errors('Invalid query parameter'),
                            '401' => $errors('Missing, malformed or unknown Customer-Id'),
                        ],
                    ],
                    'post' => [
                        'operationId' => 'createPurchase',
                        'summary' => 'Buy books',
                        'parameters' => $customer,
                        'requestBody' => [
                            'required' => true,
                            'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/PurchaseCreateDocument']]],
                        ],
                        'responses' => [
                            '201' => $document('Purchase stored; Location header gives its URL', 'PurchaseDocument'),
                            '400' => $errors('Malformed document'),
                            '401' => $errors('Missing, malformed or unknown Customer-Id'),
                            '402' => $errors('Payment declined'),
                            '409' => $errors('Not enough stock'),
                            '415' => $errors('Content-Type is not application/vnd.api+json'),
                            '422' => $errors('Invalid lines'),
                            '502' => $errors('Payment service unreachable or failing'),
                        ],
                    ],
                ],
                '/purchases/{id}' => [
                    'get' => [
                        'operationId' => 'getPurchase',
                        'summary' => 'Get one purchase of the customer',
                        'parameters' => [...$customer, $ref('Id')],
                        'responses' => [
                            '200' => $document('The purchase', 'PurchaseDocument'),
                            '401' => $errors('Missing, malformed or unknown Customer-Id'),
                            '404' => $errors('Unknown purchase, or another customer\'s purchase'),
                        ],
                    ],
                ],
                '/reports/sales' => [
                    'get' => [
                        'operationId' => 'salesReport',
                        'summary' => 'Books sold between two dates, as CSV',
                        'parameters' => [$ref('ReportFrom'), $ref('ReportTo')],
                        'responses' => [
                            '200' => [
                                'description' => 'CSV file with columns title, quantity, revenueCents',
                                'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
                            ],
                            '400' => $errors('Missing, malformed or reversed dates'),
                        ],
                    ],
                ],
                '/openapi.json' => [
                    'get' => [
                        'operationId' => 'openapi',
                        'summary' => 'This OpenAPI document',
                        'responses' => [
                            '200' => ['description' => 'OpenAPI document', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]],
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

    /** @return array<string, array<string, mixed>> */
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
            'Id' => ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Database id; a malformed id answers 404', 'schema' => ['type' => 'string']],
            'CustomerId' => ['name' => 'Customer-Id', 'in' => 'header', 'required' => true, 'description' => 'Id of the customer, as an integer', 'schema' => ['type' => 'string']],
            'PageNumber' => $query('page[number]', 'Page number, from 1', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            'PageSize' => $query('page[size]', 'Items per page, 1 to 50', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
            'BookSort' => $query('sort', 'Comma separated fields among priceCents, title, publishedAt; prefix - for descending. Ties by id ascending.', ['type' => 'string']),
            'AuthorSort' => $query('sort', 'Comma separated fields among name, publishedBooks; prefix - for descending. Ties by id ascending.', ['type' => 'string']),
            'PurchaseSort' => $query('sort', 'Comma separated fields among createdAt, totalCents; prefix - for descending. Default -createdAt.', ['type' => 'string']),
            'BookInclude' => $query('include', 'Related resources to include: author', ['type' => 'string', 'enum' => ['author']]),
            'AuthorInclude' => $query('include', 'Related resources to include: books', ['type' => 'string', 'enum' => ['books']]),
            'FilterTitle' => $query('filter[title]', 'Title contains the text, case-insensitive', ['type' => 'string']),
            'FilterAuthor' => $query('filter[author]', 'Author name, exact', ['type' => 'string']),
            'FilterMinPrice' => $query('filter[minPrice]', 'Minimum price in cents, inclusive', ['type' => 'integer']),
            'FilterMaxPrice' => $query('filter[maxPrice]', 'Maximum price in cents, inclusive', ['type' => 'integer']),
            'FilterPublishedAfter' => $query('filter[publishedAfter]', 'Published on or after this date', ['type' => 'string', 'format' => 'date']),
            'FilterPublishedBefore' => $query('filter[publishedBefore]', 'Published on or before this date', ['type' => 'string', 'format' => 'date']),
            'FilterInStock' => $query('filter[inStock]', 'true: stock above 0; false: stock 0', ['type' => 'boolean']),
            'ReportFrom' => $query('from', 'First day, inclusive (UTC)', ['type' => 'string', 'format' => 'date']),
            'ReportTo' => $query('to', 'Last day, inclusive (UTC)', ['type' => 'string', 'format' => 'date']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function schemas(): array
    {
        $link = ['type' => 'string'];
        $resourceLinks = ['type' => 'object', 'properties' => ['self' => $link]];
        $pagination = [
            'type' => 'object',
            'properties' => [
                'self' => $link, 'first' => $link, 'last' => $link,
                'prev' => ['type' => 'string', 'nullable' => true],
                'next' => ['type' => 'string', 'nullable' => true],
            ],
        ];
        $meta = ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']]];
        $bookResource = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['books']],
                'id' => ['type' => 'string'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'priceCents' => ['type' => 'integer'],
                        'publishedAt' => ['type' => 'string', 'format' => 'date', 'nullable' => true],
                        'stock' => ['type' => 'integer'],
                    ],
                ],
                'relationships' => [
                    'type' => 'object',
                    'properties' => ['author' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'id' => ['type' => 'string']]]]]],
                ],
                'links' => $resourceLinks,
            ],
        ];
        $authorResource = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['authors']],
                'id' => ['type' => 'string'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'nullable' => true],
                        'publishedBooks' => ['type' => 'integer'],
                    ],
                ],
                'relationships' => ['type' => 'object'],
                'links' => $resourceLinks,
            ],
        ];
        $purchaseResource = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['purchases']],
                'id' => ['type' => 'string'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                        'totalCents' => ['type' => 'integer'],
                        'lines' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'bookId' => ['type' => 'integer'],
                                    'title' => ['type' => 'string'],
                                    'quantity' => ['type' => 'integer'],
                                    'unitPriceCents' => ['type' => 'integer'],
                                ],
                            ],
                        ],
                    ],
                ],
                'links' => $resourceLinks,
            ],
        ];
        $errorObject = [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'detail' => ['type' => 'string'],
                'source' => [
                    'type' => 'object',
                    'properties' => ['pointer' => ['type' => 'string'], 'parameter' => ['type' => 'string']],
                ],
            ],
        ];

        return [
            'ErrorDocument' => [
                'type' => 'object',
                'required' => ['errors'],
                'properties' => ['errors' => ['type' => 'array', 'items' => $errorObject]],
            ],
            'BookDocument' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $bookResource, 'included' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'BookList' => ['type' => 'object', 'required' => ['data', 'links', 'meta'], 'properties' => ['data' => ['type' => 'array', 'items' => $bookResource], 'links' => $pagination, 'meta' => $meta, 'included' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'AuthorDocument' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $authorResource, 'included' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'AuthorList' => ['type' => 'object', 'required' => ['data', 'links', 'meta'], 'properties' => ['data' => ['type' => 'array', 'items' => $authorResource], 'links' => $pagination, 'meta' => $meta, 'included' => ['type' => 'array', 'items' => ['type' => 'object']]]],
            'PurchaseDocument' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => $purchaseResource]],
            'PurchaseList' => ['type' => 'object', 'required' => ['data', 'links', 'meta'], 'properties' => ['data' => ['type' => 'array', 'items' => $purchaseResource], 'links' => $pagination, 'meta' => $meta]],
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
                                                'bookId' => ['type' => 'integer'],
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
