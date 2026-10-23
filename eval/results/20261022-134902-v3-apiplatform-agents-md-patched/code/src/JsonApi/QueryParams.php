<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validates the JSON:API query parameters (pagination, sort, include) and reads filters.
 */
final class QueryParams
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    /**
     * Parses a path identifier; null when it is not a plausible database id.
     */
    public static function pathId(string $value): ?int
    {
        return 1 === preg_match('/^\d{1,9}$/', $value) ? (int) $value : null;
    }

    /**
     * @return array{number: int, size: int}
     */
    public static function page(Request $request): array
    {
        $page = $request->query->all()['page'] ?? [];
        if (!is_array($page)) {
            throw JsonApiException::create(400, 'Invalid parameter', 'The page parameter must be page[number] and page[size].', null, 'page');
        }

        $number = self::positiveInteger($page, 'number', 'page[number]');
        $size = self::positiveInteger($page, 'size', 'page[size]');
        if (null !== $size && ($size < 1 || $size > self::MAX_PAGE_SIZE)) {
            throw JsonApiException::create(400, 'Invalid parameter', 'page[size] must be between 1 and 50.', null, 'page[size]');
        }

        return ['number' => $number ?? 1, 'size' => $size ?? self::DEFAULT_PAGE_SIZE];
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        $raw = $request->query->all()['include'] ?? null;
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!is_string($raw)) {
            throw JsonApiException::create(400, 'Invalid parameter', 'include must be a comma separated list of relationship paths.', null, 'include');
        }

        $paths = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $path) => '' !== $path));
        foreach ($paths as $path) {
            if (!in_array($path, $allowed, true)) {
                throw JsonApiException::create(400, 'Invalid parameter', sprintf('Unknown include path "%s".', $path), null, 'include');
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $allowed
     * @return list<array{field: string, descending: bool}>
     */
    public static function sort(Request $request, array $allowed): array
    {
        $raw = $request->query->all()['sort'] ?? null;
        if (null === $raw) {
            return [];
        }
        if (!is_string($raw)) {
            throw JsonApiException::create(400, 'Invalid parameter', 'sort must be a comma separated list of fields.', null, 'sort');
        }

        $sorts = [];
        foreach (explode(',', $raw) as $token) {
            $descending = str_starts_with($token, '-');
            $field = $descending ? substr($token, 1) : $token;
            if (!in_array($field, $allowed, true)) {
                throw JsonApiException::create(400, 'Invalid parameter', sprintf('Unknown sort field "%s".', $field), null, 'sort');
            }
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * Value of a filter[...] parameter, or null when absent.
     */
    public static function filter(Request $request, string $name): ?string
    {
        $filters = $request->query->all()['filter'] ?? [];
        if (!is_array($filters) || !array_key_exists($name, $filters)) {
            return null;
        }
        if (!is_string($filters[$name])) {
            throw JsonApiException::create(400, 'Invalid parameter', sprintf('filter[%s] is malformed.', $name), null, 'filter['.$name.']');
        }

        return $filters[$name];
    }

    public static function integerFilter(Request $request, string $name): ?int
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match('/^-?\d+$/', $value)) {
            throw JsonApiException::create(400, 'Invalid parameter', sprintf('filter[%s] must be an integer.', $name), null, 'filter['.$name.']');
        }

        return (int) $value;
    }

    public static function dateFilter(Request $request, string $name): ?string
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if (!self::isDate($value)) {
            throw JsonApiException::create(400, 'Invalid parameter', sprintf('filter[%s] must be a date YYYY-MM-DD.', $name), null, 'filter['.$name.']');
        }

        return $value;
    }

    public static function booleanFilter(Request $request, string $name): ?bool
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw JsonApiException::create(400, 'Invalid parameter', sprintf('filter[%s] must be true or false.', $name), null, 'filter['.$name.']');
        }

        return 'true' === $value;
    }

    public static function isDate(string $value): bool
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private static function positiveInteger(array $source, string $key, string $parameter): ?int
    {
        if (!array_key_exists($key, $source)) {
            return null;
        }
        $value = $source[$key];
        if (!is_string($value) || 1 !== preg_match('/^\d+$/', $value)) {
            throw JsonApiException::create(400, 'Invalid parameter', sprintf('%s must be an integer.', $parameter), null, $parameter);
        }
        if ('page[number]' === $parameter && (int) $value < 1) {
            throw JsonApiException::create(400, 'Invalid parameter', 'page[number] starts at 1.', null, $parameter);
        }

        return min((int) $value, 1_000_000_000);
    }
}
