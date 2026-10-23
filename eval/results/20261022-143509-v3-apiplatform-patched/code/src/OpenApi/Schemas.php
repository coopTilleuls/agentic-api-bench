<?php

namespace App\OpenApi;

/**
 * JSON Schema fragments of the JSON:API documents, shared by the OpenAPI operations.
 */
final class Schemas
{
    public const JSON_API = 'application/vnd.api+json';

    public static function error(): array
    {
        return [
            'type' => 'object',
            'required' => ['errors'],
            'properties' => [
                'errors' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['status', 'title', 'detail'],
                        'properties' => [
                            'status' => ['type' => 'string', 'example' => '422'],
                            'title' => ['type' => 'string'],
                            'detail' => ['type' => 'string'],
                            'source' => [
                                'type' => 'object',
                                'properties' => [
                                    'pointer' => ['type' => 'string', 'example' => '/data/attributes/priceCents'],
                                    'parameter' => ['type' => 'string', 'example' => 'sort'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /** A to-one relationship object with resource linkage. */
    public static function linkage(string $type): array
    {
        return ['type' => 'object', 'properties' => ['data' => self::linkageObject($type)]];
    }

    /** A resource identifier object. */
    public static function linkageObject(string $type): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'id'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => [$type]],
                'id' => ['type' => 'string', 'example' => '2'],
            ],
        ];
    }

    public static function book(): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['books']],
                'id' => ['type' => 'string', 'example' => '4'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string', 'example' => 'Dune'],
                        'priceCents' => ['type' => 'integer', 'example' => 2000],
                        'publishedAt' => ['type' => 'string', 'format' => 'date', 'nullable' => true, 'example' => '1965-08-01'],
                        'stock' => ['type' => 'integer', 'example' => 10],
                    ],
                ],
                'relationships' => [
                    'type' => 'object',
                    'properties' => [
                        'author' => self::linkage('authors'),
                    ],
                ],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string', 'format' => 'uri']]],
            ],
        ];
    }

    public static function author(): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['authors']],
                'id' => ['type' => 'string', 'example' => '2'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string', 'nullable' => true, 'example' => 'Frank Herbert'],
                        'publishedBooks' => ['type' => 'integer', 'example' => 5],
                    ],
                ],
                'relationships' => [
                    'type' => 'object',
                    'properties' => [
                        'books' => [
                            'type' => 'object',
                            'properties' => [
                                'data' => ['type' => 'array', 'items' => self::linkageObject('books')],
                            ],
                        ],
                    ],
                ],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string', 'format' => 'uri']]],
            ],
        ];
    }

    public static function includedAuthor(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['authors']],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string', 'nullable' => true]]],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string', 'format' => 'uri']]],
            ],
        ];
    }

    public static function purchase(): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => ['purchases']],
                'id' => ['type' => 'string', 'example' => '9'],
                'attributes' => [
                    'type' => 'object',
                    'properties' => [
                        'createdAt' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-10-22T12:42:46Z'],
                        'totalCents' => ['type' => 'integer', 'example' => 5200],
                        'lines' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'bookId' => ['type' => 'integer', 'example' => 4],
                                    'title' => ['type' => 'string', 'example' => 'Dune'],
                                    'quantity' => ['type' => 'integer', 'example' => 2],
                                    'unitPriceCents' => ['type' => 'integer', 'example' => 2000],
                                ],
                            ],
                        ],
                    ],
                ],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string', 'format' => 'uri']]],
            ],
        ];
    }

    /** A collection document: `data` is the array of resources, with pagination links and total. */
    public static function collection(array $resource, bool $withIncluded): array
    {
        $document = [
            'type' => 'object',
            'required' => ['data', 'links', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'items' => $resource],
                'links' => [
                    'type' => 'object',
                    'properties' => [
                        'first' => ['type' => 'string', 'format' => 'uri'],
                        'last' => ['type' => 'string', 'format' => 'uri'],
                        'prev' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                        'next' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                    ],
                ],
                'meta' => ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']]],
            ],
        ];
        if ($withIncluded) {
            $document['properties']['included'] = ['type' => 'array', 'items' => ['type' => 'object']];
        }

        return $document;
    }

    /** A single-resource document. */
    public static function single(array $resource, bool $withIncluded): array
    {
        $document = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => ['data' => $resource],
        ];
        if ($withIncluded) {
            $document['properties']['included'] = ['type' => 'array', 'items' => ['type' => 'object']];
        }

        return $document;
    }
}
