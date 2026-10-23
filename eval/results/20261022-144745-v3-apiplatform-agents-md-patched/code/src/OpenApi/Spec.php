<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;

/**
 * Parameters, bodies and responses of the operations, as described in the OpenAPI document.
 */
final class Spec
{
    private const JSONAPI = 'application/vnd.api+json';

    public static function pathId(): Parameter
    {
        return new Parameter(name: 'id', in: 'path', description: 'The id of the resource; a malformed id answers 404.', required: true, schema: ['type' => 'integer', 'minimum' => 1]);
    }

    public static function pageNumber(): Parameter
    {
        return self::query('page[number]', 'The page, from 1. A page past the last one is empty.', ['type' => 'integer', 'minimum' => 1, 'default' => 1]);
    }

    public static function pageSize(): Parameter
    {
        return self::query('page[size]', 'The number of items per page, from 1 to 50.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]);
    }

    public static function include(string $paths): Parameter
    {
        return self::query('include', "Comma-separated relationship paths to add to \"included\": $paths.", ['type' => 'string']);
    }

    public static function sort(string $fields): Parameter
    {
        return self::query('sort', "Comma-separated fields, ascending unless prefixed with \"-\", ties broken by id: $fields.", ['type' => 'string']);
    }

    public static function filter(string $name, string $description, array $schema): Parameter
    {
        return self::query("filter[$name]", $description, $schema);
    }

    public static function customerId(): Parameter
    {
        return new Parameter(name: 'Customer-Id', in: 'header', description: 'The id of the customer making the request.', required: true, schema: ['type' => 'integer', 'minimum' => 1]);
    }

    public static function query(string $name, string $description, array $schema): Parameter
    {
        return new Parameter(name: $name, in: 'query', description: $description, required: false, schema: $schema);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public static function body(string $description, array $schema): RequestBody
    {
        return new RequestBody(description: $description, content: new \ArrayObject([self::JSONAPI => new MediaType(new \ArrayObject($schema))]), required: true);
    }

    /**
     * @param array<string, mixed> $dataSchema
     */
    public static function document(string $description, array $dataSchema, bool $collection = false, bool $included = false): Response
    {
        $properties = [
            'data' => $collection ? ['type' => 'array', 'items' => $dataSchema] : $dataSchema,
            'links' => ['type' => 'object'],
            'meta' => ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']]],
        ];

        if ($included) {
            $properties['included'] = ['type' => 'array', 'items' => ['type' => 'object']];
        }

        return self::content($description, ['type' => 'object', 'required' => ['data'], 'properties' => $properties]);
    }

    public static function errors(string $description): Response
    {
        return self::content($description, [
            'type' => 'object',
            'required' => ['errors'],
            'properties' => ['errors' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'required' => ['status', 'title', 'detail'],
                'properties' => [
                    'status' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'detail' => ['type' => 'string'],
                    'source' => ['type' => 'object', 'properties' => [
                        'pointer' => ['type' => 'string'],
                        'parameter' => ['type' => 'string'],
                    ]],
                ],
            ]]],
        ]);
    }

    public static function noContent(string $description): Response
    {
        return new Response(description: $description);
    }

    /**
     * A resource object of the given type, with its attributes described.
     *
     * @param array<string, array<string, mixed>> $attributes
     *
     * @return array<string, mixed>
     */
    public static function resource(string $type, array $attributes, array $relationships = []): array
    {
        $resource = [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes', 'links'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => [$type]],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => $attributes],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]],
            ],
        ];

        if ([] !== $relationships) {
            $resource['properties']['relationships'] = ['type' => 'object', 'properties' => $relationships];
        }

        return $resource;
    }

    public static function relationship(string $type): array
    {
        return [
            'type' => 'object',
            'required' => ['data'],
            'properties' => ['data' => ['type' => 'object', 'required' => ['type', 'id'], 'properties' => [
                'type' => ['type' => 'string', 'enum' => [$type]],
                'id' => ['type' => 'string'],
            ]]],
        ];
    }

    public static function csv(string $description): Response
    {
        return new Response(description: $description, content: new \ArrayObject(['text/csv' => new MediaType(new \ArrayObject(['type' => 'string']))]));
    }

    private static function content(string $description, array $schema): Response
    {
        return new Response(description: $description, content: new \ArrayObject([self::JSONAPI => new MediaType(new \ArrayObject($schema))]));
    }
}
