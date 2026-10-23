<?php

namespace App\State;

use App\Http\JsonApiException;

/**
 * Reads the raw JSON:API request body of a write operation.
 */
final class JsonApiDocument
{
    /**
     * @return array{data: array<string, mixed>}
     */
    public static function decode(string $content): array
    {
        $document = json_decode($content, true);
        if (!\is_array($document) || !\is_array($document['data'] ?? null) || array_is_list($document['data'])) {
            throw JsonApiException::pointer(400, '/data', 'The request body must be a JSON:API document with a data object.');
        }

        return $document;
    }
}
