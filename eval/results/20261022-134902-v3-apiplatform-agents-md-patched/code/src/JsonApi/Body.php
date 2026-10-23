<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the JSON:API request document.
 */
final class Body
{
    /**
     * @throws JsonApiException 415 when the request is not sent as application/vnd.api+json
     */
    public static function requireMediaType(Request $request): void
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (Document::MEDIA_TYPE !== $mediaType) {
            throw JsonApiException::create(415, 'Unsupported media type', 'The Content-Type must be application/vnd.api+json.');
        }
    }

    /**
     * The primary data member of the request document.
     *
     * @return array<string, mixed>
     */
    public static function data(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            throw JsonApiException::create(400, 'Malformed body', 'The request body is not a valid JSON document.');
        }
        if (!is_array($body['data'] ?? null)) {
            throw JsonApiException::create(422, 'Missing data', 'The request document must have a data member.', '/data');
        }

        return $body['data'];
    }
}
