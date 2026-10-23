<?php

namespace App\Http;

use App\Exception\ApiException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and validates the JSON:API query parameters: page, sort, filter and include.
 */
final class JsonApiQuery
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    /**
     * @return array{number: int, size: int}
     */
    public static function page(Request $request): array
    {
        $page = self::arrayParameter($request, 'page');

        $number = self::integerParameter($page, 'number', 'page[number]', 1, PHP_INT_MAX) ?? 1;
        $size = self::integerParameter($page, 'size', 'page[size]', 1, self::MAX_PAGE_SIZE) ?? self::DEFAULT_PAGE_SIZE;

        return ['number' => $number, 'size' => $size];
    }

    /**
     * @param list<string> $allowed fields accepted in sort
     *
     * @return list<array{field: string, descending: bool}>
     */
    public static function sort(Request $request, array $allowed): array
    {
        if (!\array_key_exists('sort', $request->query->all())) {
            return [];
        }

        $raw = $request->query->all()['sort'];
        if (!\is_string($raw) || '' === $raw) {
            throw ApiException::badParameter('sort', 'The sort parameter must be a comma separated list of fields.');
        }

        $sort = [];
        foreach (explode(',', $raw) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!\in_array($field, $allowed, true)) {
                throw ApiException::badParameter('sort', sprintf('Unknown sort field "%s".', $field));
            }
            $sort[] = ['field' => $field, 'descending' => $descending];
        }

        return $sort;
    }

    /**
     * Returns the value of a filter[...] key, or null when it is absent or empty.
     */
    public static function filter(Request $request, string $key): ?string
    {
        $filter = self::arrayParameter($request, 'filter');
        if (!\array_key_exists($key, $filter)) {
            return null;
        }
        if (!\is_string($filter[$key])) {
            throw ApiException::badParameter('filter[' . $key . ']', 'This filter takes a single value.');
        }

        return '' === $filter[$key] ? null : $filter[$key];
    }

    public static function integerFilter(Request $request, string $key): ?int
    {
        $value = self::filter($request, $key);
        if (null === $value) {
            return null;
        }
        if (!preg_match('/^-?\d{1,9}$/', $value)) {
            throw ApiException::badParameter('filter[' . $key . ']', sprintf('"%s" must be an integer.', $value));
        }

        return (int) $value;
    }

    public static function dateFilter(Request $request, string $key): ?string
    {
        $value = self::filter($request, $key);
        if (null === $value) {
            return null;
        }
        if (!self::isDate($value)) {
            throw ApiException::badParameter('filter[' . $key . ']', sprintf('"%s" must be a date (YYYY-MM-DD).', $value));
        }

        return $value;
    }

    public static function booleanFilter(Request $request, string $key): ?bool
    {
        $value = self::filter($request, $key);
        if (null === $value) {
            return null;
        }

        return match ($value) {
            'true' => true,
            'false' => false,
            default => throw ApiException::badParameter('filter[' . $key . ']', '"' . $value . '" must be true or false.'),
        };
    }

    /**
     * @param list<string> $allowed include paths accepted
     *
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        if (!\array_key_exists('include', $request->query->all())) {
            return [];
        }

        $raw = $request->query->all()['include'];
        if (!\is_string($raw)) {
            throw ApiException::badParameter('include', 'The include parameter must be a comma separated list of relationship paths.');
        }

        $paths = [];
        foreach (explode(',', $raw) as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw ApiException::badParameter('include', sprintf('Unknown include path "%s".', $path));
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    public static function isDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayParameter(Request $request, string $key): array
    {
        $value = $request->query->all()[$key] ?? [];
        if (!\is_array($value)) {
            throw ApiException::badParameter($key, sprintf('The %s parameter must be used with brackets.', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function integerParameter(array $parameters, string $key, string $name, int $min, int $max): ?int
    {
        if (!\array_key_exists($key, $parameters)) {
            return null;
        }

        $value = $parameters[$key];
        if (!\is_string($value) || !preg_match('/^\d{1,9}$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw ApiException::badParameter($name, sprintf('%s must be an integer between %d and %d.', $name, $min, $max));
        }

        return (int) $value;
    }
}
