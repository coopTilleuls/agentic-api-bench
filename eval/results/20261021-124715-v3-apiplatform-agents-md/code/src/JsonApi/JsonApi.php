<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds JSON:API documents and responses.
 */
final class JsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $headers
     */
    public static function response(array $document, int $status = 200, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($document, $status, $headers);
        $response->headers->set('Content-Type', self::MEDIA_TYPE);

        return $response;
    }

    /**
     * A page of a collection: the data, the page links and the total number of matching items.
     *
     * @param array{number: int, size: int} $page
     * @param array{data: list<array<string, mixed>>, total: int, included: list<array<string, mixed>>} $result
     */
    public static function collection(Request $request, array $page, array $result): JsonResponse
    {
        $document = [
            'data' => $result['data'],
            'links' => QueryParams::pageLinks($request, $page['number'], $page['size'], $result['total']),
            'meta' => ['totalItems' => $result['total']],
        ];

        if ($request->query->has('include')) {
            $document['included'] = $result['included'];
        }

        return self::response($document);
    }

    /**
     * @param list<array<string, mixed>> $errors
     */
    public static function errorResponse(int $status, array $errors): JsonResponse
    {
        return self::response(['errors' => $errors], $status);
    }

    /**
     * Whether a Content-Type header names the JSON:API media type (parameters ignored).
     */
    public static function isJsonApiContentType(?string $contentType): bool
    {
        if (null === $contentType) {
            return false;
        }

        return strtolower(trim(explode(';', $contentType, 2)[0])) === self::MEDIA_TYPE;
    }

    /**
     * Decodes a JSON:API request body and returns its primary `data` object.
     *
     * @return array<string, mixed>
     */
    public static function requestData(string $content, string $type): array
    {
        try {
            $body = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw JsonApiException::single(400, 'Bad Request', 'The request body is not valid JSON.');
        }

        if (!\is_array($body) || !\is_array($body['data'] ?? null)) {
            throw JsonApiException::single(422, 'Unprocessable Entity', 'The request body must contain a "data" object.', '/data');
        }

        if (($body['data']['type'] ?? null) !== $type) {
            throw JsonApiException::single(409, 'Conflict', sprintf('The resource type must be "%s".', $type), '/data/type');
        }

        return $body['data'];
    }
}
