<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON:API helpers: documents, error objects, request parsing and pagination links.
 */
final class JsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /**
     * @param array<string, mixed> $document
     * @param array<string, string> $headers
     */
    public static function document(array $document, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($document, $status, $headers + ['Content-Type' => self::MEDIA_TYPE]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function error(int $status, string $title, string $detail, ?string $pointer = null, ?string $parameter = null): array
    {
        $error = ['status' => (string) $status, 'title' => $title, 'detail' => $detail];
        if (null !== $pointer) {
            $error['source'] = ['pointer' => $pointer];
        }
        if (null !== $parameter) {
            $error['source'] = ['parameter' => $parameter];
        }

        return $error;
    }

    /**
     * Parses a positive id; null when the value is not a usable database id.
     */
    public static function id(string $raw): ?int
    {
        return preg_match('/^[1-9]\d{0,8}$/', $raw) ? (int) $raw : null;
    }

    /**
     * Returns the date as given when it is a real calendar date written YYYY-MM-DD, null otherwise.
     */
    public static function date(string $raw): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $matches)) {
            return null;
        }

        return checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1]) ? $raw : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw JsonApiException::one(400, 'Malformed request body', 'The request body is not valid JSON.');
        }
        if (!is_array($decoded)) {
            throw JsonApiException::one(400, 'Malformed request body', 'The request body must be a JSON object.');
        }

        return $decoded;
    }

    public static function hasJsonApiContentType(Request $request): bool
    {
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));

        return self::MEDIA_TYPE === $type;
    }

    public static function page(Request $request): Page
    {
        $page = $request->query->all()['page'] ?? [];
        $page = is_array($page) ? $page : [];
        $number = $page['number'] ?? '1';
        $size = $page['size'] ?? '10';

        if (!is_string($number) || !preg_match('/^[1-9]\d{0,8}$/', $number)) {
            throw JsonApiException::one(400, 'Invalid query parameter', 'page[number] must be a positive integer.', parameter: 'page[number]');
        }
        if (!is_string($size) || !preg_match('/^\d+$/', $size) || (int) $size < 1 || (int) $size > 50) {
            throw JsonApiException::one(400, 'Invalid query parameter', 'page[size] must be an integer from 1 to 50.', parameter: 'page[size]');
        }

        return new Page((int) $number, (int) $size);
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function includes(Request $request, array $allowed): array
    {
        $raw = $request->query->all()['include'] ?? '';
        if (!is_string($raw)) {
            throw JsonApiException::one(400, 'Invalid query parameter', 'include must be a comma separated list of paths.', parameter: 'include');
        }
        if ('' === $raw) {
            return [];
        }

        $paths = explode(',', $raw);
        foreach ($paths as $path) {
            if (!in_array($path, $allowed, true)) {
                throw JsonApiException::one(400, 'Invalid query parameter', sprintf('Unknown include path "%s".', $path), parameter: 'include');
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array{first: string, last: string, prev: ?string, next: ?string}
     */
    public static function pageLinks(Request $request, Page $page, int $total): array
    {
        $last = max(1, (int) ceil($total / $page->size));

        return [
            'first' => self::pageUrl($request, 1, $page->size),
            'last' => self::pageUrl($request, $last, $page->size),
            'prev' => $page->number > 1 ? self::pageUrl($request, min($page->number - 1, $last), $page->size) : null,
            'next' => $page->number < $last ? self::pageUrl($request, $page->number + 1, $page->size) : null,
        ];
    }

    private static function pageUrl(Request $request, int $number, int $size): string
    {
        $query = $request->query->all();
        $page = is_array($query['page'] ?? null) ? $query['page'] : [];
        $query['page'] = array_merge($page, ['number' => $number, 'size' => $size]);

        return $request->getPathInfo().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
