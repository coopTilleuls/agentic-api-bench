<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validation helpers for query parameters and request bodies.
 */
final class Input
{
    /** Parses a query parameter value holding a non-negative integer within the bounds, or answers 400. */
    public static function digits(mixed $raw, string $parameter, int $min, int $max): int
    {
        if (!is_string($raw) || !preg_match('/^[0-9]+$/', $raw)) {
            throw JsonApiException::badRequest($parameter, sprintf('%s must be an integer.', $parameter));
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if (false === $value) {
            throw JsonApiException::badRequest($parameter, sprintf('%s must be between %d and %d.', $parameter, $min, $max));
        }

        return $value;
    }

    /** Parses a query parameter value holding a signed integer, or answers 400. */
    public static function integer(mixed $raw, string $parameter): int
    {
        if (!is_string($raw) || !preg_match('/^-?[0-9]{1,18}$/', $raw)) {
            throw JsonApiException::badRequest($parameter, sprintf('%s must be an integer.', $parameter));
        }

        return (int) $raw;
    }

    /** Parses a query parameter value holding a calendar date (YYYY-MM-DD), or answers 400. */
    public static function date(mixed $raw, string $parameter): string
    {
        if (!is_string($raw) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw JsonApiException::badRequest($parameter, sprintf('%s must be a date in YYYY-MM-DD format.', $parameter));
        }

        return $raw;
    }

    /** Parses a query parameter value holding a text, or answers 400. */
    public static function text(mixed $raw, string $parameter): string
    {
        if (!is_string($raw)) {
            throw JsonApiException::badRequest($parameter, sprintf('%s must be a single value.', $parameter));
        }

        return $raw;
    }

    /**
     * Returns the requested compound-document paths, each one checked against the allowed ones.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function includes(Request $request, array $allowed): array
    {
        $raw = $request->query->get('include');
        if (null === $raw || '' === $raw) {
            return [];
        }

        $paths = explode(',', self::text($raw, 'include'));
        foreach ($paths as $path) {
            if (!in_array($path, $allowed, true)) {
                throw JsonApiException::badRequest('include', sprintf('Unknown include path "%s".', $path));
            }
        }

        return $paths;
    }

    /**
     * Returns the JSON:API request body as an array after checking its media type, or answers 415/400.
     *
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        $mediaType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (JsonApiResponse::MEDIA_TYPE !== $mediaType) {
            throw JsonApiException::error(415, 'Unsupported Media Type', 'The Content-Type must be application/vnd.api+json.');
        }

        $content = (string) $request->getContent();
        // Decoded without the assoc flag, so that an empty object is told apart from an empty array.
        if (!json_decode($content) instanceof \stdClass) {
            throw JsonApiException::error(400, 'Bad Request', 'The request body must be a JSON object.');
        }

        return json_decode($content, true);
    }
}
