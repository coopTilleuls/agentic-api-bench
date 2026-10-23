<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class JsonApiError
{
    public const CONTENT_TYPE = 'application/vnd.api+json';

    public static function response(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): JsonResponse
    {
        $error = ['status' => (string) $status, 'title' => $title, 'detail' => $detail];
        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        } elseif (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return new JsonResponse(['errors' => [$error]], $status, ['Content-Type' => self::CONTENT_TYPE]);
    }
}
