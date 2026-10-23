<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Parses the JSON:API query parameters (filter, sort, page, include).
 * Malformed values answer 400 with the offending parameter in source.parameter.
 */
final class QueryParams
{
    private const INT_PATTERN = '/^-?\d+$/';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /**
     * @return array<string, string> raw filter values, keyed by filter name
     */
    public static function filters(Request $request): array
    {
        $filter = $request->query->all()['filter'] ?? [];
        if (!\is_array($filter)) {
            throw self::badRequest('filter', 'The filter parameter must be a filter[name]=value family.');
        }

        return array_filter($filter, \is_string(...));
    }

    public static function string(array $filters, string $name): ?string
    {
        return $filters[$name] ?? null;
    }

    public static function integer(array $filters, string $name): ?int
    {
        if (!isset($filters[$name])) {
            return null;
        }
        if (1 !== preg_match(self::INT_PATTERN, $filters[$name])) {
            throw self::badRequest("filter[$name]", "filter[$name] must be an integer.");
        }

        return (int) $filters[$name];
    }

    public static function boolean(array $filters, string $name): ?bool
    {
        if (!isset($filters[$name])) {
            return null;
        }

        return match ($filters[$name]) {
            'true' => true,
            'false' => false,
            default => throw self::badRequest("filter[$name]", "filter[$name] must be true or false."),
        };
    }

    public static function date(array $filters, string $name): ?string
    {
        if (!isset($filters[$name])) {
            return null;
        }

        return self::parseDate($filters[$name], "filter[$name]");
    }

    /**
     * Parses a required or optional YYYY-MM-DD query parameter.
     */
    public static function parseDate(string $value, string $parameter): string
    {
        if (1 !== preg_match(self::DATE_PATTERN, $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw self::badRequest($parameter, "$parameter must be a date formatted YYYY-MM-DD.");
        }

        return $value;
    }

    /**
     * @param array<string, string> $fields sort name => SQL column
     *
     * @return list<string> SQL ORDER BY terms, in the order given
     */
    public static function sort(Request $request, array $fields): array
    {
        if (!$request->query->has('sort')) {
            return [];
        }
        $raw = $request->query->get('sort');
        if (!\is_string($raw) || '' === trim($raw)) {
            throw self::badRequest('sort', 'The sort parameter must be a comma separated list of fields.');
        }

        $terms = [];
        foreach (explode(',', $raw) as $token) {
            $token = trim($token);
            $descending = str_starts_with($token, '-');
            $name = $descending ? substr($token, 1) : $token;
            if (!isset($fields[$name])) {
                throw self::badRequest('sort', \sprintf('Unknown sort field "%s".', $name));
            }
            $terms[] = $fields[$name].($descending ? ' DESC' : ' ASC');
        }

        return $terms;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string> include paths, in the order given
     */
    public static function include(Request $request, array $allowed): array
    {
        if (!$request->query->has('include')) {
            return [];
        }
        $raw = $request->query->get('include');
        if (!\is_string($raw)) {
            throw self::badRequest('include', 'The include parameter must be a comma separated list of relationships.');
        }

        $paths = [];
        foreach (array_filter(array_map(trim(...), explode(',', $raw)), static fn (string $path): bool => '' !== $path) as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw self::badRequest('include', \sprintf('Unknown include path "%s".', $path));
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    public static function badRequest(string $parameter, string $detail): ApiException
    {
        return new ApiException(400, $detail, null, $parameter);
    }
}
