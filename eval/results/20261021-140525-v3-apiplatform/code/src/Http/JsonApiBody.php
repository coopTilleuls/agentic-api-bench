<?php

namespace App\Http;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the JSON:API document sent in a request body.
 */
final class JsonApiBody
{
    /**
     * @return array<string, mixed> the resource object of "data"
     */
    public static function resource(Request $request, string $type): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ('application/vnd.api+json' !== $contentType) {
            throw ApiException::single(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }

        $document = json_decode($request->getContent(), true);
        if (JSON_ERROR_NONE !== json_last_error() || !\is_array($document)) {
            throw ApiException::single(400, 'Bad Request', 'The request body is not valid JSON.');
        }

        $data = $document['data'] ?? null;
        if (!\is_array($data) || array_is_list($data)) {
            throw ApiException::invalidBody('/data', 'The document must contain a "data" object.');
        }

        if (isset($data['type']) && $type !== $data['type']) {
            throw ApiException::single(409, 'Conflict', sprintf('The resource type must be "%s".', $type));
        }

        return $data;
    }
}
