<?php

namespace App\OpenApi;

/**
 * Adds to the OpenAPI document of API Platform what the attributes of the resources cannot express:
 * the request bodies and the error responses of each operation.
 */
final class Documentation
{
    private const JSON_API = 'application/vnd.api+json';

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function apply(array $document): array
    {
        $errorResponse = static fn (string $description): array => [
            'description' => $description,
            'content' => [self::JSON_API => ['schema' => self::errorSchema()]],
        ];

        self::addResponses($document, '/books', 'get', ['400' => $errorResponse('An invalid query parameter.')]);
        self::addResponses($document, '/books/{id}', 'get', [
            '400' => $errorResponse('An invalid include parameter.'),
            '404' => $errorResponse('No published book has this id.'),
        ]);
        self::addRequestBody($document, '/books/{id}', 'patch', self::bookPatchBody(), [
            '204' => ['description' => 'The book is not published: nothing to return.'],
            '404' => $errorResponse('No book has this id.'),
            '409' => $errorResponse('The id of the body is not the id of the URL.'),
            '415' => $errorResponse('The Content-Type is not application/vnd.api+json.'),
            '422' => $errorResponse('Invalid attributes: nothing is changed.'),
        ]);

        self::addResponses($document, '/authors', 'get', ['400' => $errorResponse('An invalid query parameter.')]);
        self::addResponses($document, '/authors/{id}', 'get', [
            '400' => $errorResponse('An invalid include parameter.'),
            '404' => $errorResponse('Unknown author.'),
        ]);

        self::addResponses($document, '/purchases', 'get', [
            '400' => $errorResponse('An invalid query parameter.'),
            '401' => $errorResponse('The Customer-Id header is missing, malformed or unknown.'),
        ]);
        self::addResponses($document, '/purchases/{id}', 'get', [
            '401' => $errorResponse('The Customer-Id header is missing, malformed or unknown.'),
            '404' => $errorResponse("No purchase of this customer has this id."),
        ]);
        self::addRequestBody($document, '/purchases', 'post', self::purchasePostBody(), [
            '401' => $errorResponse('The Customer-Id header is missing, malformed or unknown.'),
            '402' => $errorResponse('The payment was declined.'),
            '409' => $errorResponse('Not enough stock for a book.'),
            '415' => $errorResponse('The Content-Type is not application/vnd.api+json.'),
            '422' => $errorResponse('A line is invalid, or references an unpublished book.'),
            '502' => $errorResponse('The payment service did not answer as expected.'),
        ]);

        self::addResponses($document, '/reports/sales', 'get', [
            '400' => $errorResponse('from or to is missing, malformed, or from is after to.'),
        ]);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, array<string, mixed>> $responses
     */
    private static function addResponses(array &$document, string $path, string $method, array $responses): void
    {
        if (!isset($document['paths'][$path][$method])) {
            return;
        }

        $document['paths'][$path][$method]['responses'] = array_replace($document['paths'][$path][$method]['responses'] ?? [], $responses);
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $requestBody
     * @param array<string, array<string, mixed>> $responses
     */
    private static function addRequestBody(array &$document, string $path, string $method, array $requestBody, array $responses): void
    {
        if (!isset($document['paths'][$path][$method])) {
            return;
        }

        $document['paths'][$path][$method]['requestBody'] = $requestBody;
        self::addResponses($document, $path, $method, $responses);
    }

    /**
     * @return array<string, mixed>
     */
    private static function bookPatchBody(): array
    {
        return [
            'description' => 'The attributes to change; the others are kept. Only title, priceCents and stock are used.',
            'required' => true,
            'content' => [self::JSON_API => ['schema' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => [
                    'type' => 'object',
                    'required' => ['type', 'id', 'attributes'],
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['books']],
                        'id' => ['type' => 'string', 'description' => 'Must be the id of the book in the URL.'],
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
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function purchasePostBody(): array
    {
        return [
            'description' => 'The lines of the purchase. Every line must be a published book, with enough stock.',
            'required' => true,
            'content' => [self::JSON_API => ['schema' => [
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
                                        'bookId' => ['type' => 'integer'],
                                        'quantity' => ['type' => 'integer', 'minimum' => 1],
                                    ],
                                ],
                            ]],
                        ],
                    ],
                ]],
            ]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['errors'],
            'properties' => ['errors' => [
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
            ]],
        ];
    }
}
