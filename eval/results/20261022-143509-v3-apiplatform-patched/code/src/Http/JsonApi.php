<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds JSON:API documents and error documents.
 */
final class JsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    private const TITLES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        409 => 'Conflict',
        415 => 'Unsupported Media Type',
        422 => 'Unprocessable Entity',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
    ];

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $headers
     */
    public static function document(array $document, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($document, $status, $headers + ['Content-Type' => self::MEDIA_TYPE]);
    }

    public static function error(ApiException $exception): JsonResponse
    {
        return self::errors([$exception], $exception->getStatus());
    }

    /**
     * @param list<ApiException> $exceptions
     */
    public static function errors(array $exceptions, int $status): JsonResponse
    {
        return self::document([
            'errors' => array_map(self::errorObject(...), $exceptions),
        ], $status);
    }

    public static function errorObject(ApiException $exception): array
    {
        $error = [
            'status' => (string) $exception->getStatus(),
            'title' => self::TITLES[$exception->getStatus()] ?? 'Error',
            'detail' => $exception->getDetail(),
        ];
        if (null !== $exception->getPointer()) {
            $error['source'] = ['pointer' => $exception->getPointer()];
        } elseif (null !== $exception->getParameter()) {
            $error['source'] = ['parameter' => $exception->getParameter()];
        }

        return $error;
    }

    /**
     * Absolute URL of the request, without the query string.
     */
    public static function baseUrl(Request $request): string
    {
        return $request->getSchemeAndHttpHost();
    }

    /**
     * Whether the request body declares the JSON:API media type.
     */
    public static function hasJsonApiContentType(Request $request): bool
    {
        $contentType = $request->headers->get('Content-Type', '');

        return self::MEDIA_TYPE === strtolower(trim(explode(';', $contentType)[0]));
    }
}
