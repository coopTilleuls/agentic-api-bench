<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads a JSON:API request document.
 */
final class RequestBody
{
    private const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * The "data" member of the document, checked to be a JSON object.
     *
     * @return array<string, mixed>
     */
    public static function data(Request $request): array
    {
        self::assertMediaType($request);

        $document = json_decode($request->getContent(), true);
        if (!\is_array($document)) {
            throw JsonApiException::create(400, 'Bad Request', 'The request body is not a valid JSON document.');
        }

        $data = $document['data'] ?? null;
        if (!\is_array($data) || array_is_list($data)) {
            throw JsonApiException::invalid('/data', 'The document must have a "data" object.');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function attributes(array $data): array
    {
        $attributes = $data['attributes'] ?? [];
        if (!\is_array($attributes) || array_is_list($attributes)) {
            throw JsonApiException::invalid('/data/attributes', 'The attributes must be an object.');
        }

        return $attributes;
    }

    public static function assertMediaType(Request $request): void
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (self::MEDIA_TYPE !== $contentType) {
            throw JsonApiException::create(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }
    }
}
