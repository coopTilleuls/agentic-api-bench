<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;

/**
 * OpenAPI responses and request bodies of the JSON:API endpoints.
 */
final class JsonApiDocs
{
    private const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param array<string, mixed> $schema
     */
    public static function document(string $description, array $schema): Response
    {
        return new Response(description: $description, content: new \ArrayObject([self::MEDIA_TYPE => ['schema' => $schema]]));
    }

    public static function error(string $description): Response
    {
        return self::document($description, self::errorDocument());
    }

    public static function noContent(string $description): Response
    {
        return new Response(description: $description);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function body(string $description, array $schema): RequestBody
    {
        return new RequestBody(description: $description, content: new \ArrayObject([self::MEDIA_TYPE => ['schema' => $schema]]), required: true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function singleDocument(string $resourceType): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => self::resource($resourceType),
                'links' => self::links(['self' => ['type' => 'string']]),
                'included' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function collectionDocument(string $resourceType): array
    {
        return [
            'type' => 'object',
            'required' => ['data', 'links', 'meta'],
            'properties' => [
                'data' => ['type' => 'array', 'items' => self::resource($resourceType)],
                'links' => self::links(['self' => ['type' => 'string'], 'first' => ['type' => 'string'], 'last' => ['type' => 'string'], 'prev' => ['type' => 'string'], 'next' => ['type' => 'string']]),
                'meta' => ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']]],
                'included' => ['type' => 'array', 'items' => ['type' => 'object']],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorDocument(): array
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
                            'source' => ['type' => 'object', 'properties' => [
                                'pointer' => ['type' => 'string', 'example' => '/data/attributes/priceCents'],
                                'parameter' => ['type' => 'string', 'example' => 'page[size]'],
                            ]],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function bookResource(): array
    {
        return self::resource('books', [
            'title' => ['type' => 'string'],
            'priceCents' => ['type' => 'integer'],
            'publishedAt' => ['type' => ['string', 'null'], 'format' => 'date'],
            'stock' => ['type' => 'integer'],
        ], ['author' => self::relationship('authors')]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function authorResource(bool $withBooks): array
    {
        return self::resource('authors', [
            'name' => ['type' => ['string', 'null']],
            'publishedBooks' => ['type' => 'integer'],
        ], $withBooks ? ['books' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['books']], 'id' => ['type' => 'string']]]]]]] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public static function purchaseResource(): array
    {
        return self::resource('purchases', [
            'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            'totalCents' => ['type' => 'integer'],
            'lines' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => [
                    'bookId' => ['type' => 'integer'],
                    'title' => ['type' => 'string'],
                    'quantity' => ['type' => 'integer'],
                    'unitPriceCents' => ['type' => 'integer'],
                ],
            ]],
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $relationships
     * @return array<string, mixed>
     */
    private static function resource(string $type, array $attributes = [], array $relationships = []): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['type', 'id', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => [$type]],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => $attributes],
                'links' => self::links(['self' => ['type' => 'string', 'example' => "/$type/1"]]),
            ],
        ];
        if ([] !== $relationships) {
            $schema['properties']['relationships'] = ['type' => 'object', 'properties' => $relationships];
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function relationship(string $type): array
    {
        return ['type' => 'object', 'properties' => ['data' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => [$type]], 'id' => ['type' => 'string']]]]];
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private static function links(array $properties): array
    {
        return ['type' => 'object', 'properties' => $properties];
    }
}
