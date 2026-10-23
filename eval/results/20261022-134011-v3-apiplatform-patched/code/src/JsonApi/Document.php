<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds JSON:API responses.
 */
final class Document
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public static function respond(array $body, int $status = 200, array $headers = []): Response
    {
        $json = json_encode(
            ['jsonapi' => ['version' => '1.1']] + $body,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return new Response($json, $status, ['Content-Type' => self::MEDIA_TYPE] + $headers);
    }


    /**
     * @param list<array<string, mixed>> $errors
     * @param array<string, string>      $headers
     */
    public static function errors(array $errors, int $status, array $headers = []): Response
    {
        return self::respond(['errors' => $errors], $status, $headers);
    }
    /**
     * Rejects a request body whose media type is not JSON:API.
     */
    public static function requireMediaType(Request $request): void
    {
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if (self::MEDIA_TYPE !== $type) {
            throw ApiException::status(415, 'Content-Type must be application/vnd.api+json.');
        }
    }

    /**
     * Decodes a JSON object request body.
     *
     * @return array<string, mixed>
     */
    public static function decodeBody(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ApiException::status(400, 'The request body is not valid JSON.');
        }
        if (!\is_array($decoded)) {
            throw ApiException::status(400, 'The request body must be a JSON object.');
        }

        return $decoded;
    }
}
