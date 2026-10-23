<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Parses and validates JSON:API query parameters, and builds pagination links.
 */
final class Query
{
    public const DEFAULT_SIZE = 10;
    public const MAX_SIZE = 50;
    private const MAX_NUMBER = 1000000000;
    private const MAX_ID = 2147483647;

    /**
     * @return array{0: int, 1: int} [page number, page size]
     */
    public static function page(Request $request): array
    {
        $page = self::arrayParam($request, 'page');
        $number = isset($page['number']) ? self::integer($page['number'], 'page[number]', 1, self::MAX_NUMBER) : 1;
        $size = isset($page['size']) ? self::integer($page['size'], 'page[size]', 1, self::MAX_SIZE) : self::DEFAULT_SIZE;

        return [$number, $size];
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        $raw = $request->query->get('include');
        if (null === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            throw ApiException::parameter('include', 'include must be a comma separated list of relationship paths.');
        }
        $paths = '' === $raw ? [] : array_values(array_unique(explode(',', $raw)));
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw ApiException::parameter('include', sprintf('Unknown include path "%s".', $path));
            }
        }

        return $paths;
    }

    /**
     * @param array<string, string> $fields sort name => SQL column
     *
     * @return list<string> ORDER BY terms, in the order given
     */
    public static function sort(Request $request, array $fields): array
    {
        $raw = $request->query->get('sort');
        if (null === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            throw ApiException::parameter('sort', 'sort must be a comma separated list of fields.');
        }

        $terms = [];
        foreach (explode(',', $raw) as $token) {
            $descending = str_starts_with($token, '-');
            $name = $descending ? substr($token, 1) : $token;
            if (!isset($fields[$name])) {
                throw ApiException::parameter('sort', sprintf('Unknown sort field "%s".', $name));
            }
            $terms[] = $fields[$name].($descending ? ' DESC' : ' ASC');
        }

        return $terms;
    }

    public static function filterString(Request $request, string $name): ?string
    {
        $value = self::arrayParam($request, 'filter')[$name] ?? null;
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw ApiException::parameter('filter['.$name.']', sprintf('filter[%s] must be a string.', $name));
    }

    public static function filterInteger(Request $request, string $name): ?int
    {
        $value = self::filterString($request, $name);
        if (null === $value) {
            return null;
        }
        if (!preg_match('/\A-?\d+\z/', $value)) {
            throw ApiException::parameter('filter['.$name.']', sprintf('filter[%s] must be an integer.', $name));
        }

        return (int) $value;
    }

    public static function filterDate(Request $request, string $name): ?string
    {
        $value = self::filterString($request, $name);
        if (null === $value) {
            return null;
        }
        if (!self::isDate($value)) {
            throw ApiException::parameter('filter['.$name.']', sprintf('filter[%s] must be a date YYYY-MM-DD.', $name));
        }

        return $value;
    }

    public static function filterBoolean(Request $request, string $name): ?bool
    {
        $value = self::filterString($request, $name);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw ApiException::parameter('filter['.$name.']', sprintf('filter[%s] must be true or false.', $name));
        }

        return 'true' === $value;
    }

    /**
     * A required YYYY-MM-DD query parameter.
     */
    public static function requiredDate(Request $request, string $name): string
    {
        $value = $request->query->get($name);
        if (!\is_string($value) || !self::isDate($value)) {
            throw ApiException::parameter($name, sprintf('%s is required and must be a date YYYY-MM-DD.', $name));
        }

        return $value;
    }

    public static function isDate(string $value): bool
    {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Parses an id taken from the path; null when it cannot be an existing id.
     */
    public static function pathId(string $raw): ?int
    {
        if (!preg_match('/\A\d+\z/', $raw) || (int) $raw < 1 || (int) $raw > self::MAX_ID) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * @return array<string, string|null>
     */
    public static function links(Request $request, int $number, int $size, int $total): array
    {
        $last = max(1, (int) ceil($total / $size));

        return [
            'self' => self::url($request, $number, $size),
            'first' => self::url($request, 1, $size),
            'last' => self::url($request, $last, $size),
            'prev' => $number > 1 ? self::url($request, min($number - 1, $last), $size) : null,
            'next' => $number < $last ? self::url($request, $number + 1, $size) : null,
        ];
    }

    private static function url(Request $request, int $number, int $size): string
    {
        $query = $request->query->all();
        $page = \is_array($query['page'] ?? null) ? $query['page'] : [];
        $query['page'] = ['number' => (string) $number, 'size' => (string) $size] + $page;

        return $request->getPathInfo().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string, mixed>
     */
    private static function arrayParam(Request $request, string $name): array
    {
        $all = $request->query->all();

        return \is_array($all[$name] ?? null) ? $all[$name] : [];
    }

    private static function integer(mixed $value, string $name, int $min, int $max): int
    {
        if (!\is_string($value) || !preg_match('/\A\d+\z/', $value) || (int) $value < $min || (int) $value > $max) {
            throw ApiException::parameter($name, sprintf('%s must be an integer between %d and %d.', $name, $min, $max));
        }

        return (int) $value;
    }
}
