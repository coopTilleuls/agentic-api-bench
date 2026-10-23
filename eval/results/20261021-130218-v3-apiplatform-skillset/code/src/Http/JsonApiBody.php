<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the primary data of a JSON:API request document.
 */
final class JsonApiBody
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @return array<string, mixed> the "data" member of the document
     */
    public static function data(Request $request, string $type): array
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'), 2)[0]));
        if (self::MEDIA_TYPE !== $mediaType) {
            throw ApiException::single(415, sprintf('The Content-Type must be "%s".', self::MEDIA_TYPE));
        }

        try {
            $document = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::single(400, 'The request body is not valid JSON.');
        }

        if (!is_array($document) || !is_array($document['data'] ?? null)) {
            throw ApiException::single(400, 'The request document must contain a "data" object.', ['pointer' => '/data']);
        }

        $data = $document['data'];
        if (($data['type'] ?? null) !== $type) {
            throw ApiException::single(409, sprintf('The resource type must be "%s".', $type), ['pointer' => '/data/type']);
        }

        return $data;
    }
}
