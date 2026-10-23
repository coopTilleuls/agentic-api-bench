<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\JsonResponse;

final class JsonApiResponse
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param array<string, mixed>  $document
     * @param array<string, string> $headers
     */
    public static function document(array $document, int $status = 200, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($document, $status, ['Content-Type' => self::MEDIA_TYPE] + $headers);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response;
    }

    /**
     * @param list<array<string, mixed>> $errors
     * @param array<string, string>      $headers
     */
    public static function errors(array $errors, int $status, array $headers = []): JsonResponse
    {
        return self::document(['errors' => $errors], $status, $headers);
    }
}
