<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the JSON:API request document of a write, checking its media type first.
 */
final class JsonApiBody
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @return array<string, mixed>
     */
    public static function read(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (self::MEDIA_TYPE !== $contentType) {
            throw JsonApiException::single(415, 'Unsupported media type', 'The Content-Type header must be application/vnd.api+json.');
        }

        try {
            $document = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw JsonApiException::single(400, 'Malformed document', 'The request body is not valid JSON.');
        }
        if (!\is_array($document) || array_is_list($document)) {
            throw JsonApiException::single(400, 'Malformed document', 'The request body must be a JSON object.');
        }

        return $document;
    }
}
