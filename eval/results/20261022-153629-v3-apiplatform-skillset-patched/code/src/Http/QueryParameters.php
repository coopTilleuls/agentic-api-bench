<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validates the JSON:API query parameters of a request. Invalid values answer 400 naming the parameter.
 */
final class QueryParameters
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    private function __construct()
    {
    }

    /**
     * @return array{0: int, 1: int} the page number and the page size
     */
    public static function page(Request $request): array
    {
        $page = $request->query->all()['page'] ?? [];
        if (!\is_array($page)) {
            throw JsonApiException::parameter(400, 'page', 'The page parameter must be page[number] and page[size].');
        }

        $number = self::positiveInteger($page, 'number', 'page[number]', 1, PHP_INT_MAX) ?? 1;
        $size = self::positiveInteger($page, 'size', 'page[size]', 1, self::MAX_PAGE_SIZE) ?? self::DEFAULT_PAGE_SIZE;

        return [$number, $size];
    }

    /**
     * The raw value of filter[$name], or null when absent. Unknown filters are ignored by the callers.
     */
    public static function filter(Request $request, string $name): ?string
    {
        $filters = $request->query->all()['filter'] ?? [];
        if (!\is_array($filters) || !\array_key_exists($name, $filters)) {
            return null;
        }
        if (!\is_string($filters[$name])) {
            throw JsonApiException::parameter(400, "filter[$name]", "The filter[$name] parameter must be a single value.");
        }

        return $filters[$name];
    }

    public static function integerFilter(Request $request, string $name): ?int
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match('/^-?\d{1,15}$/', $value)) {
            throw JsonApiException::parameter(400, "filter[$name]", "The filter[$name] parameter must be an integer.");
        }

        return (int) $value;
    }

    public static function booleanFilter(Request $request, string $name): ?bool
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw JsonApiException::parameter(400, "filter[$name]", "The filter[$name] parameter must be true or false.");
        }

        return 'true' === $value;
    }

    public static function dateFilter(Request $request, string $name): ?string
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if (!self::isDate($value)) {
            throw JsonApiException::parameter(400, "filter[$name]", "The filter[$name] parameter must be a date (YYYY-MM-DD).");
        }

        return $value;
    }

    public static function isDate(string $value): bool
    {
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<array{field: string, descending: bool}>
     */
    public static function sort(Request $request, array $allowed): array
    {
        if (!$request->query->has('sort')) {
            return [];
        }
        $sort = $request->query->get('sort');
        if (!\is_string($sort)) {
            throw JsonApiException::parameter(400, 'sort', 'The sort parameter must be a comma separated list of fields.');
        }

        $sorts = [];
        foreach (explode(',', $sort) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!\in_array($field, $allowed, true)) {
                throw JsonApiException::parameter(400, 'sort', \sprintf('Unknown sort field "%s". Allowed fields: %s.', $field, implode(', ', $allowed)));
            }
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        if (!$request->query->has('include')) {
            return [];
        }
        $include = $request->query->get('include');
        if (!\is_string($include)) {
            throw JsonApiException::parameter(400, 'include', 'The include parameter must be a comma separated list of relationship paths.');
        }

        $paths = '' === $include ? [] : explode(',', $include);
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw JsonApiException::parameter(400, 'include', \sprintf('Unknown include path "%s". Allowed paths: %s.', $path, implode(', ', $allowed)));
            }
        }

        return $paths;
    }

    /**
     * @param array<mixed> $values
     */
    private static function positiveInteger(array $values, string $key, string $parameter, int $min, int $max): ?int
    {
        if (!\array_key_exists($key, $values)) {
            return null;
        }
        $value = $values[$key];
        if (!\is_string($value) || 1 !== preg_match('/^\d{1,15}$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw JsonApiException::parameter(400, $parameter, \sprintf('%s must be an integer between %d and %d.', $parameter, $min, $max));
        }

        return (int) $value;
    }
}
