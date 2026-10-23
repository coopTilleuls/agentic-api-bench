<?php

namespace App\OpenApi;

/**
 * The OpenAPI 3.0 description of the API, served at /openapi.json.
 */
final class Specification
{
    private const JSONAPI = 'application/vnd.api+json';

    /** @return array<string, mixed> */
    public static function build(string $server): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Bookshop API',
                'version' => '1.0.0',
                'description' => 'JSON:API 1.1 bookshop. Responses are application/vnd.api+json, except the CSV report and 204.',
            ],
            'servers' => [['url' => $server]],
            'paths' => [
                '/authors' => ['get' => self::listOperation('List the authors', 'Authors', ['pageNumber', 'pageSize'], 'AuthorsDocument', 'authors')],
                '/authors/{id}' => ['get' => [
                    'operationId' => 'showAuthor',
                    'summary' => 'Show an author with the list of their published books',
                    'tags' => ['Authors'],
                    'parameters' => [self::ref('id'), self::ref('include')],
                    'responses' => [
                        '200' => self::response('AuthorDocument', 'The author'),
                        '400' => self::errorResponse('Invalid include'),
                        '404' => self::errorResponse('Unknown author'),
                    ],
                ]],
                '/books' => [
                    'get' => self::listOperation('List the published books', 'Books', [
                        'pageNumber', 'pageSize', 'sort', 'include',
                        'filterTitle', 'filterAuthor', 'filterMinPrice', 'filterMaxPrice',
                        'filterPublishedAfter', 'filterPublishedBefore', 'filterInStock',
                    ], 'BooksDocument', 'books'),
                ],
                '/books/{id}' => [
                    'get' => [
                        'operationId' => 'showBook',
                        'summary' => 'Show a published book',
                        'tags' => ['Books'],
                        'parameters' => [self::ref('id'), self::ref('include')],
                        'responses' => [
                            '200' => self::response('BookDocument', 'The book'),
                            '400' => self::errorResponse('Invalid include'),
                            '404' => self::errorResponse('Unknown or unpublished book'),
                        ],
                    ],
                    'patch' => [
                        'operationId' => 'updateBook',
                        'summary' => 'Update the title, price and stock of a book, published or not',
                        'tags' => ['Books'],
                        'parameters' => [self::ref('id')],
                        'requestBody' => [
                            'required' => true,
                            'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/BookUpdateDocument']]],
                        ],
                        'responses' => [
                            '200' => self::response('BookDocument', 'The updated book (published books only)'),
                            '204' => ['description' => 'The book is unpublished, nothing to return'],
                            '400' => self::errorResponse('Malformed JSON'),
                            '404' => self::errorResponse('Unknown book'),
                            '409' => self::errorResponse('data.id or data.type does not match the path'),
                            '415' => self::errorResponse('Content type is not application/vnd.api+json'),
                            '422' => self::errorResponse('Invalid attributes'),
                        ],
                    ],
                ],
                '/purchases' => [
                    'get' => [
                        'operationId' => 'listPurchases',
                        'summary' => "List the customer's purchases, most recent first",
                        'tags' => ['Purchases'],
                        'parameters' => [self::ref('customerId'), self::ref('pageNumber'), self::ref('pageSize')],
                        'responses' => [
                            '200' => self::response('PurchasesDocument', 'The purchases of the page'),
                            '400' => self::errorResponse('Invalid pagination'),
                            '401' => self::errorResponse('Missing, malformed or unknown Customer-Id'),
                        ],
                    ],
                    'post' => [
                        'operationId' => 'createPurchase',
                        'summary' => 'Buy books',
                        'tags' => ['Purchases'],
                        'parameters' => [self::ref('customerId')],
                        'requestBody' => [
                            'required' => true,
                            'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/PurchaseCreateDocument']]],
                        ],
                        'responses' => [
                            '201' => array_merge(self::response('PurchaseDocument', 'The purchase'), [
                                'headers' => ['Location' => ['description' => 'URL of the purchase', 'schema' => ['type' => 'string']]],
                            ]),
                            '400' => self::errorResponse('Malformed JSON'),
                            '401' => self::errorResponse('Missing, malformed or unknown Customer-Id'),
                            '402' => self::errorResponse('Payment declined'),
                            '409' => self::errorResponse('Not enough stock'),
                            '415' => self::errorResponse('Content type is not application/vnd.api+json'),
                            '422' => self::errorResponse('Invalid lines, or a book that is not published'),
                            '502' => self::errorResponse('Payment service failed'),
                        ],
                    ],
                ],
                '/purchases/{id}' => [
                    'get' => [
                        'operationId' => 'showPurchase',
                        'summary' => "Show one of the customer's purchases",
                        'tags' => ['Purchases'],
                        'parameters' => [self::ref('customerId'), self::ref('id')],
                        'responses' => [
                            '200' => self::response('PurchaseDocument', 'The purchase'),
                            '401' => self::errorResponse('Missing, malformed or unknown Customer-Id'),
                            '404' => self::errorResponse('Unknown purchase, or another customer\'s purchase'),
                        ],
                    ],
                ],
                '/reports/sales' => [
                    'get' => [
                        'operationId' => 'salesReport',
                        'summary' => 'Books sold between two days, as CSV',
                        'tags' => ['Reports'],
                        'parameters' => [
                            ['name' => 'from', 'in' => 'query', 'required' => true, 'description' => 'First day, inclusive (UTC)', 'schema' => ['type' => 'string', 'format' => 'date']],
                            ['name' => 'to', 'in' => 'query', 'required' => true, 'description' => 'Last day, inclusive (UTC)', 'schema' => ['type' => 'string', 'format' => 'date']],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'CSV file with the columns title, quantity, revenueCents',
                                'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
                            ],
                            '400' => self::errorResponse('Missing, malformed date, or from after to'),
                        ],
                    ],
                ],
                '/openapi.json' => [
                    'get' => [
                        'operationId' => 'openapi',
                        'summary' => 'This OpenAPI document',
                        'tags' => ['Meta'],
                        'responses' => ['200' => ['description' => 'The OpenAPI document', 'content' => [self::JSONAPI => ['schema' => ['type' => 'object']]]]],
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
     * @param list<string> $parameters names of the components parameters
     *
     * @return array<string, mixed>
     */
    private static function listOperation(string $summary, string $tag, array $parameters, string $schema, string $operationId): array
    {
        return [
            'operationId' => 'list'.$tag,
            'summary' => $summary,
            'tags' => [$tag],
            'parameters' => array_map(static fn (string $name): array => self::ref($name), $parameters),
            'responses' => [
                '200' => self::response($schema, 'The page of '.$operationId),
                '400' => self::errorResponse('Invalid parameter'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function response(string $schema, string $description): array
    {
        return [
            'description' => $description,
            'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]],
        ];
    }

    /** @return array<string, mixed> */
    private static function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/ErrorDocument']]],
        ];
    }

    /** @return array<string, mixed> */
    private static function ref(string $name): array
    {
        return ['$ref' => '#/components/parameters/'.$name];
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
        $integer = ['type' => 'integer'];

        return [
            'id' => ['name' => 'id', 'in' => 'path', 'required' => true, 'description' => 'Database id', 'schema' => ['type' => 'string', 'pattern' => '^\d+$']],
            'customerId' => [
                'name' => 'Customer-Id', 'in' => 'header', 'required' => true,
                'description' => 'Id of the customer making the request', 'schema' => ['type' => 'string', 'pattern' => '^[1-9]\d*$'],
            ],
            'pageNumber' => $query('page[number]', 'Page number, from 1', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            'pageSize' => $query('page[size]', 'Items per page, from 1 to 50', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
            'sort' => $query('sort', 'Comma separated fields, - prefix for descending. Books: priceCents, title, publishedAt', ['type' => 'string']),
            'include' => $query('include', 'Comma separated relationships to include. Books: author. Author: books', ['type' => 'string']),
            'filterTitle' => $query('filter[title]', 'Title contains the text, case-insensitive', ['type' => 'string']),
            'filterAuthor' => $query('filter[author]', 'Author name, exact', ['type' => 'string']),
            'filterMinPrice' => $query('filter[minPrice]', 'Minimum price in cents, inclusive', $integer),
            'filterMaxPrice' => $query('filter[maxPrice]', 'Maximum price in cents, inclusive', $integer),
            'filterPublishedAfter' => $query('filter[publishedAfter]', 'Published on or after this day', ['type' => 'string', 'format' => 'date']),
            'filterPublishedBefore' => $query('filter[publishedBefore]', 'Published on or before this day', ['type' => 'string', 'format' => 'date']),
            'filterInStock' => $query('filter[inStock]', 'true: stock above 0, false: stock is 0', ['type' => 'boolean']),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function schemas(): array
    {
        $links = ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]];
        $pageLinks = ['type' => 'object', 'properties' => [
            'self' => ['type' => 'string'], 'first' => ['type' => 'string'], 'last' => ['type' => 'string'],
            'prev' => ['type' => 'string', 'nullable' => true], 'next' => ['type' => 'string', 'nullable' => true],
        ]];
        $listMeta = ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']]];
        $identifier = static fn (string $type): array => [
            'type' => 'object',
            'properties' => ['type' => ['type' => 'string', 'enum' => [$type]], 'id' => ['type' => 'string']],
            'required' => ['type', 'id'],
        ];
        $book = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['books']],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => [
                    'title' => ['type' => 'string'],
                    'priceCents' => ['type' => 'integer'],
                    'publishedAt' => ['type' => 'string', 'format' => 'date'],
                    'stock' => ['type' => 'integer'],
                ]],
                'relationships' => ['type' => 'object', 'properties' => [
                    'author' => ['type' => 'object', 'properties' => ['data' => $identifier('authors')]],
                ]],
                'links' => $links,
            ],
        ];
        $author = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['authors']],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => [
                    'name' => ['type' => 'string', 'nullable' => true],
                    'publishedBooks' => ['type' => 'integer'],
                ]],
                'relationships' => ['type' => 'object', 'properties' => [
                    'books' => ['type' => 'object', 'properties' => [
                        'data' => ['type' => 'array', 'items' => $identifier('books')],
                    ]],
                ]],
                'links' => $links,
            ],
        ];
        $purchase = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['purchases']],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => [
                    'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                    'totalCents' => ['type' => 'integer'],
                    'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                        'bookId' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'quantity' => ['type' => 'integer'],
                        'unitPriceCents' => ['type' => 'integer'],
                    ]]],
                ]],
                'links' => $links,
            ],
        ];
        $included = ['type' => 'array', 'items' => ['oneOf' => [
            ['$ref' => '#/components/schemas/Book'], ['$ref' => '#/components/schemas/Author'],
        ]]];
        $document = static fn (string $name, array $data, bool $list, bool $withIncluded = false): array => [
            'type' => 'object',
            'required' => ['data'],
            'properties' => array_filter([
                'data' => $data,
                'links' => $list ? $pageLinks : $links,
                'meta' => $list ? $listMeta : null,
                'included' => $withIncluded ? $included : null,
            ], static fn (mixed $v): bool => null !== $v),
        ];

        return [
            'Book' => $book,
            'Author' => $author,
            'Purchase' => $purchase,
            'BookDocument' => $document('BookDocument', ['$ref' => '#/components/schemas/Book'], false, true),
            'BooksDocument' => $document('BooksDocument', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Book']], true, true),
            'AuthorDocument' => $document('AuthorDocument', ['$ref' => '#/components/schemas/Author'], false, true),
            'AuthorsDocument' => $document('AuthorsDocument', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Author']], true),
            'PurchaseDocument' => $document('PurchaseDocument', ['$ref' => '#/components/schemas/Purchase'], false),
            'PurchasesDocument' => $document('PurchasesDocument', ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Purchase']], true),
            'BookUpdateDocument' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => [
                    'type' => 'object',
                    'required' => ['type', 'id', 'attributes'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['books']],
                        'id' => ['type' => 'string'],
                        'attributes' => ['type' => 'object', 'properties' => [
                            'title' => ['type' => 'string', 'minLength' => 1],
                            'priceCents' => ['type' => 'integer', 'minimum' => 0],
                            'stock' => ['type' => 'integer', 'minimum' => 0],
                        ]],
                    ],
                ]],
            ],
            'PurchaseCreateDocument' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => [
                    'type' => 'object',
                    'required' => ['type', 'attributes'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['purchases']],
                        'attributes' => ['type' => 'object', 'required' => ['lines'], 'properties' => [
                            'lines' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'required' => ['bookId', 'quantity'], 'properties' => [
                                'bookId' => ['type' => 'integer', 'minimum' => 1],
                                'quantity' => ['type' => 'integer', 'minimum' => 1],
                            ]]],
                        ]],
                    ],
                ]],
            ],
            'ErrorDocument' => [
                'type' => 'object',
                'required' => ['errors'],
                'properties' => ['errors' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'status' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'detail' => ['type' => 'string'],
                    'source' => ['type' => 'object', 'properties' => [
                        'pointer' => ['type' => 'string'],
                        'parameter' => ['type' => 'string'],
                    ]],
                ]]]],
            ],
        ];
    }
}
