<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Model;
use App\JsonApi\Document;

/**
 * OpenAPI building blocks for the JSON:API operations.
 */
final class Spec
{
    private const ERROR_SCHEMA = [
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
                                'pointer' => ['type' => 'string', 'example' => '/data/attributes/priceCents'],
                                'parameter' => ['type' => 'string', 'example' => 'sort'],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $relationships
     * @return array<string, mixed>
     */
    public static function resourceSchema(string $type, array $attributes, array $relationships = []): array
    {
        $schema = [
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
            $schema['properties']['relationships'] = ['type' => 'object', 'properties' => $relationships];
        }

        return $schema;
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    public static function documentSchema(array $resource, bool $collection, array $included = []): array
    {
        $schema = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => $collection ? ['type' => 'array', 'items' => $resource] : $resource,
            ],
        ];
        if ($collection) {
            $schema['properties']['links'] = [
                'type' => 'object',
                'properties' => [
                    'first' => ['type' => 'string'],
                    'last' => ['type' => 'string'],
                    'prev' => ['type' => 'string', 'nullable' => true],
                    'next' => ['type' => 'string', 'nullable' => true],
                ],
            ];
            $schema['properties']['meta'] = ['type' => 'object', 'properties' => ['totalItems' => ['type' => 'integer']]];
        }
        if ([] !== $included) {
            $schema['properties']['included'] = ['type' => 'array', 'items' => ['oneOf' => $included]];
        }

        return $schema;
    }

    public static function jsonResponse(string $description, ?array $schema = null, ?array $headers = null): Model\Response
    {
        $media = new Model\MediaType(new \ArrayObject($schema ?? ['type' => 'object']));

        return new Model\Response(
            description: $description,
            content: new \ArrayObject([Document::MEDIA_TYPE => $media]),
            headers: $headers ? new \ArrayObject($headers) : null,
        );
    }

    public static function errorResponse(string $description): Model\Response
    {
        return self::jsonResponse($description, self::ERROR_SCHEMA);
    }

    public static function noContentResponse(string $description): Model\Response
    {
        return new Model\Response(description: $description);
    }

    public static function query(string $name, string $description, array $schema, bool $required = false): Model\Parameter
    {
        return new Model\Parameter($name, 'query', $description, $required, false, false, $schema);
    }

    public static function header(string $name, string $description, bool $required = true): Model\Parameter
    {
        return new Model\Parameter($name, 'header', $description, $required, false, false, ['type' => 'string']);
    }

    /**
     * @return list<Model\Parameter>
     */
    public static function pagination(): array
    {
        return [
            self::query('page[number]', 'Page number, from 1.', ['type' => 'integer', 'minimum' => 1, 'default' => 1]),
            self::query('page[size]', 'Items per page, from 1 to 50.', ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 10]),
        ];
    }

    /**
     * @param list<string> $values
     */
    public static function include(array $values): Model\Parameter
    {
        return self::query('include', 'Related resources to include: '.implode(', ', $values).'.', ['type' => 'string'], false);
    }

    /**
     * @param list<Model\Parameter> $parameters
     * @param array<string, Model\Response> $responses
     */
    public static function operation(string $operationId, string $summary, array $parameters, array $responses, ?Model\RequestBody $requestBody = null): Model\Operation
    {
        return new Model\Operation(
            operationId: $operationId,
            tags: [],
            responses: $responses,
            summary: $summary,
            parameters: $parameters,
            requestBody: $requestBody,
        );
    }

    public static function jsonRequestBody(array $schema): Model\RequestBody
    {
        return new Model\RequestBody(
            description: 'JSON:API document.',
            content: new \ArrayObject([Document::MEDIA_TYPE => new Model\MediaType(new \ArrayObject($schema))]),
            required: true,
        );
    }
}
