<?php

namespace App\JsonApi;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents, errors, query parameters and pagination shared by the controllers.
 */
final class JsonApi
{
    public const MEDIA_TYPE = 'application/vnd.api+json';

    /** @return array<string, mixed> */
    public static function error(int $status, string $detail, ?string $pointer = null, ?string $parameter = null): array
    {
        $error = [
            'status' => (string) $status,
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'detail' => $detail,
        ];

        $source = [];
        if (null !== $pointer) {
            $source['pointer'] = $pointer;
        }
        if (null !== $parameter) {
            $source['parameter'] = $parameter;
        }
        if ($source) {
            $error['source'] = $source;
        }

        return $error;
    }

    /** @param array<string, string> $headers */
    public static function document(array $body, int $status = 200, array $headers = []): JsonResponse
    {
        $response = new JsonResponse($body, $status, ['Content-Type' => self::MEDIA_TYPE] + $headers);
        $response->setEncodingOptions(JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $response;
    }

    public static function requireMediaType(Request $request): void
    {
        $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'))[0]));
        if (self::MEDIA_TYPE !== $contentType) {
            throw ApiException::of(415, 'The Content-Type header must be application/vnd.api+json.');
        }
    }

    /** @return array<string, mixed> the "data" member of the request document */
    public static function body(Request $request): array
    {
        $document = json_decode($request->getContent(), true);
        if (!is_array($document) || !isset($document['data']) || !is_array($document['data']) || array_is_list($document['data'])) {
            throw ApiException::of(400, 'The request body must be a JSON:API document with a "data" object.');
        }

        return $document['data'];
    }

    /** Raw query parameter, arrays included (InputBag::get refuses them). */
    public static function param(Request $request, string $name): mixed
    {
        return $request->query->all()[$name] ?? null;
    }

    /** Parses a path or header identifier; null when it is not a plausible database id. */
    public static function id(string $raw): ?int
    {
        return preg_match('/^[0-9]{1,9}$/', $raw) ? (int) $raw : null;
    }

    /** Comma separated list of ids for an SQL IN clause; "NULL" matches nothing. */
    public static function inList(array $ids): string
    {
        $ids = array_map('intval', array_unique($ids));

        return $ids ? implode(',', $ids) : 'NULL';
    }

    /** Authenticates the request by its Customer-Id header; 401 when absent, malformed or unknown. */
    public static function customerId(Request $request, Connection $db): int
    {
        $id = self::id((string) $request->headers->get('Customer-Id'));
        if (null === $id || 0 === $id || false === $db->fetchOne('SELECT 1 FROM customer WHERE id = :id', ['id' => $id])) {
            throw ApiException::of(401, 'A valid Customer-Id header is required.');
        }

        return $id;
    }

    /**
     * @return array{0: int, 1: int} page number and page size
     */
    public static function pagination(Request $request): array
    {
        $page = self::param($request, 'page') ?? [];
        if (!is_array($page)) {
            throw ApiException::of(400, 'The page parameter must look like page[number]=1&page[size]=10.', parameter: 'page');
        }

        $number = self::positiveInt($page['number'] ?? null, 'page[number]') ?? 1;
        $size = self::positiveInt($page['size'] ?? null, 'page[size]') ?? 10;
        if ($size > 50) {
            throw ApiException::of(400, 'page[size] must be between 1 and 50.', parameter: 'page[size]');
        }

        return [$number, $size];
    }

    private static function positiveInt(mixed $value, string $parameter): ?int
    {
        if (null === $value) {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[0-9]{1,15}$/', $value) || 0 === (int) $value) {
            throw ApiException::of(400, "$parameter must be a positive integer.", parameter: $parameter);
        }

        return (int) $value;
    }

    /**
     * Translates the "sort" parameter into SQL ORDER BY fragments.
     *
     * @param array<string, string> $columns sortable field name => SQL expression
     *
     * @return list<string>
     */
    public static function sortOrder(Request $request, array $columns): array
    {
        $raw = self::param($request, 'sort');
        if (null === $raw) {
            return [];
        }
        if (!is_string($raw)) {
            throw ApiException::of(400, 'The sort parameter must be a comma separated list of fields.', parameter: 'sort');
        }

        $order = [];
        foreach (explode(',', $raw) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!isset($columns[$field])) {
                throw ApiException::of(400, "Unsupported sort field \"$item\".", parameter: 'sort');
            }
            $order[] = $columns[$field].($descending ? ' DESC' : ' ASC');
        }

        return $order;
    }

    /**
     * @param list<string> $allowed include paths accepted by the endpoint
     *
     * @return list<string>
     */
    public static function includes(Request $request, array $allowed): array
    {
        $raw = self::param($request, 'include');
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!is_string($raw)) {
            throw ApiException::of(400, 'The include parameter must be a comma separated list of paths.', parameter: 'include');
        }

        $paths = [];
        foreach (explode(',', $raw) as $path) {
            $path = trim($path);
            if (!in_array($path, $allowed, true)) {
                throw ApiException::of(400, "Unsupported include path \"$path\".", parameter: 'include');
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /** Raw value of filter[$name], null when absent or empty. */
    public static function filter(Request $request, string $name): ?string
    {
        $filters = self::param($request, 'filter') ?? [];
        $value = is_array($filters) ? ($filters[$name] ?? null) : null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_string($value)) {
            throw ApiException::of(400, "filter[$name] must be a single value.", parameter: "filter[$name]");
        }

        return $value;
    }

    /** Integer filter, clamped to the range of the integer columns. */
    public static function filterInt(Request $request, string $name): ?int
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if (!preg_match('/^-?[0-9]{1,12}$/', $value)) {
            throw ApiException::of(400, "filter[$name] must be an integer.", parameter: "filter[$name]");
        }

        return max(-2147483648, min(2147483647, (int) $value));
    }

    public static function filterBool(Request $request, string $name): ?bool
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw ApiException::of(400, "filter[$name] must be true or false.", parameter: "filter[$name]");
        }

        return 'true' === $value;
    }

    public static function filterDate(Request $request, string $name): ?string
    {
        $value = self::filter($request, $name);
        if (null !== $value && !self::isDate($value)) {
            throw ApiException::of(400, "filter[$name] must be a date like 2020-01-31.", parameter: "filter[$name]");
        }

        return $value;
    }

    /** Required date query parameter. */
    public static function dateParameter(Request $request, string $name): string
    {
        $value = self::param($request, $name);
        if (!is_string($value) || '' === $value) {
            throw ApiException::of(400, "The $name parameter is required and must be a date like 2026-01-31.", parameter: $name);
        }
        if (!self::isDate($value)) {
            throw ApiException::of(400, "The $name parameter must be a date like 2026-01-31.", parameter: $name);
        }

        return $value;
    }

    /** PostgreSQL answers booleans as 't' or 'f' through PDO. */
    public static function isTrue(mixed $value): bool
    {
        return true === $value || 't' === $value;
    }

    public static function isDate(string $value): bool
    {
        return (bool) preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $m)
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Fetches one page of a list: the total count and the rows of the requested page.
     *
     * @param array<string, mixed> $params
     *
     * @return array{rows: list<array<string, mixed>>, total: int, number: int, size: int, last: int}
     */
    public static function fetchPage(Connection $db, Request $request, string $select, string $fromWhere, array $params, string $orderBy): array
    {
        [$number, $size] = self::pagination($request);

        $total = (int) $db->fetchOne("SELECT COUNT(*) $fromWhere", $params);
        $last = max(1, intdiv($total + $size - 1, $size));

        $rows = [];
        if ($number <= $last) {
            $offset = ($number - 1) * $size;
            $rows = $db->fetchAllAssociative("SELECT $select $fromWhere ORDER BY $orderBy LIMIT $size OFFSET $offset", $params);
        }

        return ['rows' => $rows, 'total' => $total, 'number' => $number, 'size' => $size, 'last' => $last];
    }

    /**
     * @param array{rows: list<array<string, mixed>>, total: int, number: int, size: int, last: int} $page
     * @param list<array<string, mixed>>                                                           $data
     * @param list<array<string, mixed>>                                                           $included
     */
    public static function listDocument(Request $request, array $page, array $data, array $included = []): JsonResponse
    {
        $number = $page['number'];
        $size = $page['size'];
        $last = $page['last'];

        $body = [
            'data' => $data,
            'links' => [
                'self' => self::pageUrl($request, $number, $size),
                'first' => self::pageUrl($request, 1, $size),
                'last' => self::pageUrl($request, $last, $size),
                'prev' => $number > 1 ? self::pageUrl($request, min($number - 1, $last), $size) : null,
                'next' => $number < $last ? self::pageUrl($request, $number + 1, $size) : null,
            ],
            'meta' => ['totalItems' => $page['total']],
        ];
        if ($included) {
            $body['included'] = $included;
        }

        return self::document($body);
    }

    /** Same path and query as the request, with another page; relative URL. */
    private static function pageUrl(Request $request, int $number, int $size): string
    {
        $params = $request->query->all();
        $params['page'] = ['number' => $number, 'size' => $size];

        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    if (is_scalar($subValue)) {
                        $pairs[] = rawurlencode((string) $key).'['.rawurlencode((string) $subKey).']='.rawurlencode((string) $subValue);
                    }
                }
            } elseif (is_scalar($value)) {
                $pairs[] = rawurlencode((string) $key).'='.rawurlencode((string) $value);
            }
        }

        return $request->getPathInfo().'?'.implode('&', $pairs);
    }
}
