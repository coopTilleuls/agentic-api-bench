<?php

namespace App\OpenApi;

/**
 * The OpenAPI 3.1 description of the bookshop operations.
 */
final class Specification
{
    private const JSONAPI = 'application/vnd.api+json';

    /** @return array<string, mixed> */
    public static function document(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Bookshop API',
                'version' => '1.0.0',
                'description' => 'JSON:API 1.1 bookshop. Customers identify themselves with the Customer-Id header on purchases.',
            ],
            'paths' => [
                '/authors' => ['get' => self::authorList()],
                '/authors/{id}' => ['get' => self::authorGet()],
                '/books' => ['get' => self::bookList()],
                '/books/{id}' => [
                    'get' => self::bookGet(),
                    'patch' => self::bookPatch(),
                ],
                '/purchases' => [
                    'get' => self::purchaseList(),
                    'post' => self::purchaseCreate(),
                ],
                '/purchases/{id}' => ['get' => self::purchaseGet()],
                '/reports/sales' => ['get' => self::salesReport()],
                '/openapi.json' => ['get' => self::openApi()],
            ],
            'components' => [
                'parameters' => self::componentParameters(),
                'schemas' => self::componentSchemas(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function authorList(): array
    {
        return [
            'operationId' => 'listAuthors',
            'summary' => 'List authors',
            'parameters' => [['$ref' => '#/components/parameters/pageNumber'], ['$ref' => '#/components/parameters/pageSize']],
            'responses' => [
                '200' => self::jsonResponse('Collection of authors.', 'AuthorCollection'),
                '400' => self::errorResponse('Invalid query parameter.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function authorGet(): array
    {
        return [
            'operationId' => 'getAuthor',
            'summary' => 'Get an author',
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'include', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['books']]],
            ],
            'responses' => [
                '200' => self::jsonResponse('The author.', 'AuthorDocument'),
                '400' => self::errorResponse('Invalid include path.'),
                '404' => self::errorResponse('Unknown author.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function bookList(): array
    {
        return [
            'operationId' => 'listBooks',
            'summary' => 'List published books',
            'parameters' => [
                ['name' => 'filter[title]', 'in' => 'query', 'required' => false, 'description' => 'Title contains the text, case-insensitive.', 'schema' => ['type' => 'string']],
                ['name' => 'filter[author]', 'in' => 'query', 'required' => false, 'description' => 'Author name, exact.', 'schema' => ['type' => 'string']],
                ['name' => 'filter[minPrice]', 'in' => 'query', 'required' => false, 'description' => 'Minimum price in cents, included.', 'schema' => ['type' => 'integer']],
                ['name' => 'filter[maxPrice]', 'in' => 'query', 'required' => false, 'description' => 'Maximum price in cents, included.', 'schema' => ['type' => 'integer']],
                ['name' => 'filter[publishedAfter]', 'in' => 'query', 'required' => false, 'description' => 'Published on or after this date.', 'schema' => ['type' => 'string', 'format' => 'date']],
                ['name' => 'filter[publishedBefore]', 'in' => 'query', 'required' => false, 'description' => 'Published on or before this date.', 'schema' => ['type' => 'string', 'format' => 'date']],
                ['name' => 'filter[inStock]', 'in' => 'query', 'required' => false, 'description' => 'true: stock above 0; false: stock 0.', 'schema' => ['type' => 'string', 'enum' => ['true', 'false']]],
                ['name' => 'sort', 'in' => 'query', 'required' => false, 'description' => 'Comma-separated fields among priceCents, title, publishedAt; prefix with - for descending.', 'schema' => ['type' => 'string']],
                ['name' => 'include', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['author']]],
                ['$ref' => '#/components/parameters/pageNumber'],
                ['$ref' => '#/components/parameters/pageSize'],
            ],
            'responses' => [
                '200' => self::jsonResponse('Collection of published books.', 'BookCollection'),
                '400' => self::errorResponse('Invalid query parameter.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function bookGet(): array
    {
        return [
            'operationId' => 'getBook',
            'summary' => 'Get a published book',
            'parameters' => [
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
                ['name' => 'include', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['author']]],
            ],
            'responses' => [
                '200' => self::jsonResponse('The book.', 'BookDocument'),
                '400' => self::errorResponse('Invalid include path.'),
                '404' => self::errorResponse('Unknown or unpublished book.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function bookPatch(): array
    {
        return [
            'operationId' => 'updateBook',
            'summary' => 'Partially update a book, published or not',
            'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
            'requestBody' => [
                'required' => true,
                'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/BookPatchDocument']]],
            ],
            'responses' => [
                '200' => self::jsonResponse('The updated book, when published.', 'BookDocument'),
                '204' => ['description' => 'The book is unpublished and has been updated.'],
                '404' => self::errorResponse('Unknown book.'),
                '409' => self::errorResponse('data.id differs from the id in the path.'),
                '415' => self::errorResponse('Content type is not application/vnd.api+json.'),
                '422' => self::errorResponse('Invalid attribute, nothing changed.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function purchaseList(): array
    {
        return [
            'operationId' => 'listPurchases',
            'summary' => 'List the purchases of the customer, most recent first',
            'parameters' => [
                ['$ref' => '#/components/parameters/customerId'],
                ['$ref' => '#/components/parameters/pageNumber'],
                ['$ref' => '#/components/parameters/pageSize'],
            ],
            'responses' => [
                '200' => self::jsonResponse('Collection of purchases.', 'PurchaseCollection'),
                '400' => self::errorResponse('Invalid query parameter.'),
                '401' => self::errorResponse('Missing, unknown or malformed Customer-Id.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function purchaseGet(): array
    {
        return [
            'operationId' => 'getPurchase',
            'summary' => 'Get a purchase of the customer',
            'parameters' => [
                ['$ref' => '#/components/parameters/customerId'],
                ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ],
            'responses' => [
                '200' => self::jsonResponse('The purchase.', 'PurchaseDocument'),
                '401' => self::errorResponse('Missing, unknown or malformed Customer-Id.'),
                '404' => self::errorResponse('Unknown purchase, or another customer\'s.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function purchaseCreate(): array
    {
        return [
            'operationId' => 'createPurchase',
            'summary' => 'Buy books',
            'parameters' => [['$ref' => '#/components/parameters/customerId']],
            'requestBody' => [
                'required' => true,
                'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/PurchaseCreateDocument']]],
            ],
            'responses' => [
                '201' => [
                    'description' => 'The purchase was stored.',
                    'headers' => ['Location' => ['description' => 'URL of the purchase.', 'schema' => ['type' => 'string']]],
                    'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/PurchaseDocument']]],
                ],
                '401' => self::errorResponse('Missing, unknown or malformed Customer-Id.'),
                '402' => self::errorResponse('The payment was declined.'),
                '409' => self::errorResponse('Not enough stock.'),
                '415' => self::errorResponse('Content type is not application/vnd.api+json.'),
                '422' => self::errorResponse('Invalid lines or unavailable book.'),
                '502' => self::errorResponse('The payment service failed.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function salesReport(): array
    {
        return [
            'operationId' => 'salesReport',
            'summary' => 'Sales of books between two dates, as CSV',
            'parameters' => [
                ['name' => 'from', 'in' => 'query', 'required' => true, 'description' => 'First day, included (UTC).', 'schema' => ['type' => 'string', 'format' => 'date']],
                ['name' => 'to', 'in' => 'query', 'required' => true, 'description' => 'Last day, included (UTC).', 'schema' => ['type' => 'string', 'format' => 'date']],
            ],
            'responses' => [
                '200' => [
                    'description' => 'CSV file with the header title,quantity,revenueCents, sorted by revenueCents descending, then title, then book id.',
                    'content' => ['text/csv' => ['schema' => ['type' => 'string']]],
                ],
                '400' => self::errorResponse('Missing, malformed date, or from after to.'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function openApi(): array
    {
        return [
            'operationId' => 'openApi',
            'summary' => 'This OpenAPI document',
            'responses' => [
                '200' => ['description' => 'OpenAPI 3.1 document.', 'content' => [self::JSONAPI => ['schema' => ['type' => 'object']]]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function componentParameters(): array
    {
        return [
            'pageNumber' => ['name' => 'page[number]', 'in' => 'query', 'required' => false, 'description' => 'Page number, from 1.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            'pageSize' => ['name' => 'page[size]', 'in' => 'query', 'required' => false, 'description' => 'Items per page, from 1 to 50.', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]],
            'customerId' => ['name' => 'Customer-Id', 'in' => 'header', 'required' => true, 'description' => 'Id of the customer.', 'schema' => ['type' => 'string']],
        ];
    }

    /** @return array<string, mixed> */
    private static function componentSchemas(): array
    {
        $links = ['type' => 'object', 'properties' => ['self' => ['type' => 'string']], 'required' => ['self']];
        $pageLinks = [
            'type' => 'object',
            'properties' => [
                'self' => ['type' => 'string'],
                'first' => ['type' => 'string'],
                'last' => ['type' => 'string'],
                'prev' => ['type' => ['string', 'null']],
                'next' => ['type' => ['string', 'null']],
            ],
        ];
        $meta = ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']], 'required' => ['totalItems']];

        $bookResource = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'const' => 'books'],
                'id' => ['type' => 'string'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'priceCents' => ['type' => 'integer'],
                        'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date'],
                        'stock' => ['type' => 'integer'],
                    ],
                ],
                'relationships' => [
                    'type' => 'object',
                    'properties' => [
                        'author' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/ResourceIdentifierAuthor']]],
                    ],
                ],
                'links' => $links,
            ],
        ];
        $authorResource = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'const' => 'authors'],
                'id' => ['type' => 'string'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => ['string', 'null']],
                        'publishedBooks' => ['type' => 'integer'],
                    ],
                ],
                'relationships' => [
                    'type' => 'object',
                    'properties' => [
                        'books' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/ResourceIdentifierBook']]]],
                    ],
                ],
                'links' => $links,
            ],
        ];
        $purchaseResource = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'const' => 'purchases'],
                'id' => ['type' => 'string'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'createdAt' => ['type' => 'string', 'format' => 'date-time'],
                        'totalCents' => ['type' => 'integer'],
                        'lines' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PurchaseLine']],
                    ],
                ],
                'links' => $links,
            ],
        ];

        $errorDocument = [
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
                            'source' => ['type' => 'object', 'properties' => ['pointer' => ['type' => 'string'], 'parameter' => ['type' => 'string']]],
                        ],
                    ],
                ],
            ],
        ];

        return [
            'ErrorDocument' => $errorDocument,
            'AuthorResource' => $authorResource,
            'BookResource' => $bookResource,
            'PurchaseResource' => $purchaseResource,
            'ResourceIdentifierAuthor' => ['type' => 'object', 'required' => ['type', 'id'], 'properties' => ['type' => ['type' => 'string', 'const' => 'authors'], 'id' => ['type' => 'string']]],
            'ResourceIdentifierBook' => ['type' => 'object', 'required' => ['type', 'id'], 'properties' => ['type' => ['type' => 'string', 'const' => 'books'], 'id' => ['type' => 'string']]],
            'AuthorDocument' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/AuthorResource'],
                    'included' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BookResource']],
                ],
            ],
            'AuthorCollection' => [
                'type' => 'object',
                'required' => ['data', 'links', 'meta'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AuthorResource']],
                    'links' => $pageLinks,
                    'meta' => $meta,
                ],
            ],
            'BookDocument' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => ['$ref' => '#/components/schemas/BookResource'],
                    'included' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AuthorResource']],
                ],
            ],
            'BookCollection' => [
                'type' => 'object',
                'required' => ['data', 'links', 'meta'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/BookResource']],
                    'included' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AuthorResource']],
                    'links' => $pageLinks,
                    'meta' => $meta,
                ],
            ],
            'BookPatchDocument' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => [
                        'type' => 'object',
                        'required' => ['type'],
                        'properties' => [
                            'type' => ['type' => 'string', 'const' => 'books'],
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
            'PurchaseLine' => [
                'type' => 'object',
                'required' => ['bookId', 'title', 'quantity', 'unitPriceCents'],
                'properties' => [
                    'bookId' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer'],
                    'unitPriceCents' => ['type' => 'integer'],
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
                            'type' => ['type' => 'string', 'const' => 'purchases'],
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
            'PurchaseDocument' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => ['$ref' => '#/components/schemas/PurchaseResource']],
            ],
            'PurchaseCollection' => [
                'type' => 'object',
                'required' => ['data', 'links', 'meta'],
                'properties' => [
                    'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PurchaseResource']],
                    'links' => $pageLinks,
                    'meta' => $meta,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function jsonResponse(string $description, string $schema): array
    {
        return ['description' => $description, 'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]]];
    }

    /** @return array<string, mixed> */
    private static function errorResponse(string $description): array
    {
        return ['description' => $description, 'content' => [self::JSONAPI => ['schema' => ['$ref' => '#/components/schemas/ErrorDocument']]]];
    }
}
