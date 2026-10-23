<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;

/**
 * OpenAPI building blocks shared by the operations: JSON:API documents, errors and parameters.
 */
final class JsonApiDocs
{
    public const MIME = 'application/vnd.api+json';

    private const ERRORS = [
        400 => 'Invalid query parameter or request body.',
        401 => 'Missing, malformed or unknown Customer-Id header.',
        402 => 'The payment was declined.',
        404 => 'Resource not found.',
        409 => 'Conflict: id mismatch in the document or not enough stock.',
        415 => 'Unsupported Content-Type, application/vnd.api+json is required.',
        422 => 'Validation error, one error per invalid field.',
        502 => 'The payment service is unavailable or failed.',
    ];

    /**
     * @param array<string, mixed> $dataSchema schema of the "data" member
     */
    public static function document(string $description, array $dataSchema): Response
    {
        return new Response(description: $description, content: new \ArrayObject([
            self::MIME => ['schema' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => [
                    'data' => $dataSchema,
                    'included' => ['type' => 'array', 'items' => ['type' => 'object']],
                    'links' => ['type' => 'object'],
                    'meta' => ['type' => 'object'],
                ],
            ]],
        ]));
    }

    public static function collection(string $description, string $type, array $attributes): Response
    {
        return self::document($description, ['type' => 'array', 'items' => self::resource($type, $attributes)]);
    }

    public static function item(string $description, string $type, array $attributes): Response
    {
        return self::document($description, self::resource($type, $attributes));
    }

    public static function csv(string $description): Response
    {
        return new Response(description: $description, content: new \ArrayObject([
            'text/csv' => ['schema' => ['type' => 'string']],
        ]));
    }

    public static function error(int $status): Response
    {
        return new Response(description: self::ERRORS[$status] ?? 'Error', content: new \ArrayObject([
            self::MIME => ['schema' => [
                'type' => 'object',
                'required' => ['errors'],
                'properties' => ['errors' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['status', 'title', 'detail'],
                    'properties' => [
                        'status' => ['type' => 'string', 'example' => (string) $status],
                        'title' => ['type' => 'string'],
                        'detail' => ['type' => 'string'],
                        'source' => ['type' => 'object', 'properties' => [
                            'pointer' => ['type' => 'string'],
                            'parameter' => ['type' => 'string'],
                            'header' => ['type' => 'string'],
                        ]],
                    ],
                ]]],
            ]],
        ]));
    }

    public static function body(string $description, string $type, array $attributes): RequestBody
    {
        return new RequestBody(description: $description, content: new \ArrayObject([
            self::MIME => ['schema' => [
                'type' => 'object',
                'required' => ['data'],
                'properties' => ['data' => self::resource($type, $attributes)],
            ]],
        ]), required: true);
    }

    public static function query(string $name, string $description, array $schema = ['type' => 'string'], bool $required = false): Parameter
    {
        return new Parameter(name: $name, in: 'query', description: $description, required: $required, schema: $schema);
    }

    public static function header(string $name, string $description, bool $required = false): Parameter
    {
        return new Parameter(name: $name, in: 'header', description: $description, required: $required, schema: ['type' => 'string']);
    }

    /**
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     */
    private static function resource(string $type, array $attributes): array
    {
        return [
            'type' => 'object',
            'required' => ['type', 'id', 'attributes'],
            'properties' => [
                'type' => ['type' => 'string', 'enum' => [$type]],
                'id' => ['type' => 'string'],
                'attributes' => ['type' => 'object', 'properties' => $attributes],
                'relationships' => ['type' => 'object'],
                'links' => ['type' => 'object', 'properties' => ['self' => ['type' => 'string']]],
            ],
        ];
    }

    public static function pagination(): array
    {
        return [
            self::query('page[number]', 'Page number, from 1. Default 1.', ['type' => 'integer', 'default' => 1]),
            self::query('page[size]', 'Items per page, from 1 to 50. Default 10.', ['type' => 'integer', 'default' => 10]),
        ];
    }
}
