<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Model\Response;

/**
 * The documentation of each operation, by path and HTTP method.
 */
final class ApiDocumentation
{
    private const AUTHOR = ['name' => ['type' => ['string', 'null']], 'publishedBooks' => ['type' => 'integer']];
    private const BOOK = [
        'title' => ['type' => 'string'],
        'priceCents' => ['type' => 'integer'],
        'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date'],
        'stock' => ['type' => 'integer'],
    ];
    private const PURCHASE = [
        'createdAt' => ['type' => 'string', 'format' => 'date-time'],
        'totalCents' => ['type' => 'integer'],
        'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'bookId' => ['type' => 'integer'],
            'title' => ['type' => 'string'],
            'quantity' => ['type' => 'integer'],
            'unitPriceCents' => ['type' => 'integer'],
        ]]],
    ];

    /**
     * @return array<string, array<string, array{summary: string, parameters?: list<object>, requestBody?: object, responses: array<string, object>}>>
     */
    public static function operations(): array
    {
        $pagination = JsonApiDocs::pagination();
        $customer = JsonApiDocs::header('Customer-Id', 'Id of the customer, identifying who is calling.', true);
        $includeAuthor = JsonApiDocs::query('include', 'Comma separated relationships to include: author.');
        $noContent = new Response(description: 'The book was updated and is not published, so it has no document.');

        return [
            '/authors' => [
                'get' => [
                    'summary' => 'List all authors, in id order.',
                    'parameters' => [...$pagination, JsonApiDocs::query('include', 'Comma separated relationships to include: books.')],
                    'responses' => [
                        '200' => JsonApiDocs::collection('The authors of the page.', 'authors', self::AUTHOR),
                        '400' => JsonApiDocs::error(400),
                    ],
                ],
            ],
            '/authors/{id}' => [
                'get' => [
                    'summary' => 'Get one author with their published books.',
                    'parameters' => [JsonApiDocs::query('include', 'Comma separated relationships to include: books.')],
                    'responses' => [
                        '200' => JsonApiDocs::item('The author, with the list of their published books.', 'authors', self::AUTHOR),
                        '400' => JsonApiDocs::error(400),
                        '404' => JsonApiDocs::error(404),
                    ],
                ],
            ],
            '/books' => [
                'get' => [
                    'summary' => 'List the published books.',
                    'parameters' => [
                        ...$pagination,
                        $includeAuthor,
                        JsonApiDocs::query('sort', 'Comma separated fields among priceCents, title and publishedAt, ascending unless prefixed with -. Ties are broken by id.'),
                        JsonApiDocs::query('filter[title]', 'Title contains the text, case-insensitive.'),
                        JsonApiDocs::query('filter[author]', 'Author name, exact.'),
                        JsonApiDocs::query('filter[minPrice]', 'Minimum price in cents, included.', ['type' => 'integer']),
                        JsonApiDocs::query('filter[maxPrice]', 'Maximum price in cents, included.', ['type' => 'integer']),
                        JsonApiDocs::query('filter[publishedAfter]', 'Published on or after this date, YYYY-MM-DD.', ['type' => 'string', 'format' => 'date']),
                        JsonApiDocs::query('filter[publishedBefore]', 'Published on or before this date, YYYY-MM-DD.', ['type' => 'string', 'format' => 'date']),
                        JsonApiDocs::query('filter[inStock]', 'true: stock greater than 0. false: stock equal to 0.', ['type' => 'boolean']),
                    ],
                    'responses' => [
                        '200' => JsonApiDocs::collection('The books of the page.', 'books', self::BOOK),
                        '400' => JsonApiDocs::error(400),
                    ],
                ],
            ],
            '/books/{id}' => [
                'get' => [
                    'summary' => 'Get one published book.',
                    'parameters' => [$includeAuthor],
                    'responses' => [
                        '200' => JsonApiDocs::item('The book.', 'books', self::BOOK),
                        '400' => JsonApiDocs::error(400),
                        '404' => JsonApiDocs::error(404),
                    ],
                ],
                'patch' => [
                    'summary' => 'Partially update a book, published or not. Only the attributes sent change.',
                    'requestBody' => JsonApiDocs::body('The book, with the attributes to change.', 'books', [
                        'title' => ['type' => 'string', 'minLength' => 1],
                        'priceCents' => ['type' => 'integer', 'minimum' => 0],
                        'stock' => ['type' => 'integer', 'minimum' => 0],
                    ]),
                    'responses' => [
                        '200' => JsonApiDocs::item('The updated book, when it is published.', 'books', self::BOOK),
                        '204' => $noContent,
                        '404' => JsonApiDocs::error(404),
                        '409' => JsonApiDocs::error(409),
                        '415' => JsonApiDocs::error(415),
                        '422' => JsonApiDocs::error(422),
                    ],
                ],
            ],
            '/purchases' => [
                'get' => [
                    'summary' => "List the customer's purchases, most recent first.",
                    'parameters' => [$customer, ...$pagination],
                    'responses' => [
                        '200' => JsonApiDocs::collection('The purchases of the page.', 'purchases', self::PURCHASE),
                        '400' => JsonApiDocs::error(400),
                        '401' => JsonApiDocs::error(401),
                    ],
                ],
                'post' => [
                    'summary' => 'Buy books: checks the stock, takes the payment, stores the purchase and decreases the stock.',
                    'parameters' => [$customer],
                    'requestBody' => JsonApiDocs::body('The lines to buy.', 'purchases', [
                        'lines' => ['type' => 'array', 'minItems' => 1, 'items' => [
                            'type' => 'object',
                            'required' => ['bookId', 'quantity'],
                            'properties' => [
                                'bookId' => ['type' => 'integer'],
                                'quantity' => ['type' => 'integer', 'minimum' => 1],
                            ],
                        ]],
                    ]),
                    'responses' => [
                        '201' => JsonApiDocs::item('The purchase, its URL is in the Location header.', 'purchases', self::PURCHASE),
                        '400' => JsonApiDocs::error(400),
                        '401' => JsonApiDocs::error(401),
                        '402' => JsonApiDocs::error(402),
                        '409' => JsonApiDocs::error(409),
                        '415' => JsonApiDocs::error(415),
                        '422' => JsonApiDocs::error(422),
                        '502' => JsonApiDocs::error(502),
                    ],
                ],
            ],
            '/purchases/{id}' => [
                'get' => [
                    'summary' => "Get one of the customer's purchases.",
                    'parameters' => [$customer],
                    'responses' => [
                        '200' => JsonApiDocs::item('The purchase.', 'purchases', self::PURCHASE),
                        '401' => JsonApiDocs::error(401),
                        '404' => JsonApiDocs::error(404),
                    ],
                ],
            ],
            '/reports/sales' => [
                'get' => [
                    'summary' => 'Books sold between two dates (UTC, bounds included), by revenue, as a CSV file.',
                    'parameters' => [
                        JsonApiDocs::query('from', 'First day, YYYY-MM-DD.', ['type' => 'string', 'format' => 'date'], true),
                        JsonApiDocs::query('to', 'Last day, YYYY-MM-DD.', ['type' => 'string', 'format' => 'date'], true),
                    ],
                    'responses' => [
                        '200' => JsonApiDocs::csv('CSV file: header title,quantity,revenueCents, then one line per book sold.'),
                        '400' => JsonApiDocs::error(400),
                    ],
                ],
            ],
        ];
    }
}
