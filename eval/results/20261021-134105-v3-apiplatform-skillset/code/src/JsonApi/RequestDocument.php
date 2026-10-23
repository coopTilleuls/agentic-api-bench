<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the primary data of a JSON:API request body.
 */
final class RequestDocument
{
    /**
     * @return array<string, mixed> the resource object of the "data" member
     */
    public static function data(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ('application/vnd.api+json' !== $contentType) {
            throw JsonApiException::error(415, 'The Content-Type must be application/vnd.api+json.', ['header' => 'Content-Type']);
        }

        try {
            $body = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw JsonApiException::error(400, 'The request body is not valid JSON.');
        }

        $data = \is_array($body) ? ($body['data'] ?? null) : null;
        if (!\is_array($data) || array_is_list($data)) {
            throw JsonApiException::validation([['pointer' => '/data', 'detail' => 'The document must have a "data" object.']]);
        }

        return $data;
    }
}
