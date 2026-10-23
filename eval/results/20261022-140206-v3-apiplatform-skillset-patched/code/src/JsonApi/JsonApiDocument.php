<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds JSON:API documents and their responses.
 */
final class JsonApiDocument
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $headers
     */
    public static function response(array $document, int $status = 200, array $headers = []): JsonResponse
    {
        return self::json($document, $status, $headers);
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    public static function errorResponse(JsonApiException $exception): JsonResponse
    {
        return self::json(['errors' => $exception->errors], $exception->status);
    }

    /**
     * @param array<string, mixed> $resource
     * @param list<array<string, mixed>> $included
     * @return array<string, mixed>
     */
    public static function single(array $resource, array $included = []): array
    {
        $document = ['data' => $resource, 'links' => ['self' => $resource['links']['self']]];

        if ([] !== $included) {
            $document['included'] = array_values($included);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $headers
     */
    private static function json(array $document, int $status, array $headers = []): JsonResponse
    {
        $response = new JsonResponse(null, $status, ['Content-Type' => self::MEDIA_TYPE] + $headers);
        $response->setEncodingOptions(\JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $response->setData($document);

        return $response;
    }
}
