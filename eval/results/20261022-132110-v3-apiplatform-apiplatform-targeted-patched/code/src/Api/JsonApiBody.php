<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

final class JsonApiBody
{
    /**
     * Returns the primary `data` object of a JSON:API request document.
     *
     * @return array<string, mixed>
     */
    public static function data(Request $request): array
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if ('application/vnd.api+json' !== $contentType) {
            throw ApiException::status(415, 'The Content-Type must be application/vnd.api+json.');
        }

        try {
            $document = json_decode($request->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::status(400, 'The request body is not valid JSON.');
        }

        $data = \is_array($document) ? ($document['data'] ?? null) : null;
        if (!\is_array($data) || array_is_list($data)) {
            throw ApiException::status(422, 'The request document must have a data object.', pointer: '/data');
        }

        return $data;
    }
}
