<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the primary data of a JSON:API request document.
 */
final class RequestDocument
{
    /**
     * @return array<string, mixed> the "data" member of the document, of the given resource type
     */
    public static function data(Request $request, string $type): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ('application/vnd.api+json' !== $contentType) {
            throw JsonApiException::single(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }

        try {
            $document = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw JsonApiException::badRequest('The request body is not valid JSON.');
        }

        if (!\is_array($document) || !isset($document['data']) || !\is_array($document['data']) || array_is_list($document['data'])) {
            throw JsonApiException::single(400, 'Bad Request', 'The request body must be a JSON:API document with a "data" object.', '/data');
        }

        $data = $document['data'];
        if (($data['type'] ?? null) !== $type) {
            throw JsonApiException::single(409, 'Conflict', sprintf('The resource type must be "%s".', $type), '/data/type');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the attributes of the resource object
     */
    public static function attributes(array $data): array
    {
        $attributes = $data['attributes'] ?? [];
        if (!\is_array($attributes) || array_is_list($attributes)) {
            throw JsonApiException::single(400, 'Bad Request', 'The attributes must be a JSON object.', '/data/attributes');
        }

        return $attributes;
    }
}
