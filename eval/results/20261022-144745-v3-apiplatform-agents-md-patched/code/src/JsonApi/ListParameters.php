<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and validates the JSON:API query parameters of a request, and builds the pagination links.
 */
final class ListParameters
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    /**
     * @return array{number: int, size: int}
     */
    public static function page(Request $request): array
    {
        $page = $request->query->all()['page'] ?? [];

        if (!\is_array($page)) {
            throw JsonApiException::badRequest('The "page" parameter must be page[number] and page[size].', 'page');
        }

        return [
            'number' => self::integer($page['number'] ?? '1', 'page[number]', 1, 1_000_000_000),
            'size' => self::integer($page['size'] ?? (string) self::DEFAULT_PAGE_SIZE, 'page[size]', 1, self::MAX_PAGE_SIZE),
        ];
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        $raw = $request->query->all()['include'] ?? null;

        if (null === $raw) {
            return [];
        }

        if (!\is_string($raw)) {
            throw JsonApiException::badRequest('The "include" parameter must be a comma-separated list of relationship paths.', 'include');
        }

        $paths = explode(',', $raw);
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw JsonApiException::badRequest(sprintf('Unknown include path "%s".', $path), 'include');
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, string> $columns SQL column by sortable field
     *
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    public static function sort(Request $request, array $columns): array
    {
        $raw = $request->query->all()['sort'] ?? null;

        if (null === $raw) {
            return [];
        }

        if (!\is_string($raw)) {
            throw JsonApiException::badRequest('The "sort" parameter must be a comma-separated list of fields.', 'sort');
        }

        $sort = [];
        foreach (explode(',', $raw) as $token) {
            $descending = str_starts_with($token, '-');
            $field = $descending ? substr($token, 1) : $token;

            if (!isset($columns[$field])) {
                throw JsonApiException::badRequest(sprintf('Unknown sort field "%s".', $field), 'sort');
            }

            $sort[] = [$columns[$field], $descending ? 'DESC' : 'ASC'];
        }

        return $sort;
    }

    /**
     * The raw value of a filter[name] parameter, null when absent.
     */
    public static function filter(Request $request, string $name): ?string
    {
        $filters = $request->query->all()['filter'] ?? [];
        $value = \is_array($filters) ? ($filters[$name] ?? null) : null;

        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw JsonApiException::badRequest(sprintf('The filter[%s] parameter is malformed.', $name), 'filter['.$name.']');
        }

        return $value;
    }

    public static function integerFilter(string $name, string $value): int
    {
        if (!preg_match('/^-?\d{1,10}$/', $value)) {
            throw JsonApiException::badRequest(sprintf('filter[%s] must be an integer.', $name), 'filter['.$name.']');
        }

        return (int) $value;
    }

    public static function booleanFilter(string $name, string $value): bool
    {
        if ('true' !== $value && 'false' !== $value) {
            throw JsonApiException::badRequest(sprintf('filter[%s] must be true or false.', $name), 'filter['.$name.']');
        }

        return 'true' === $value;
    }

    public static function dateFilter(string $name, string $value): string
    {
        if (!self::isDate($value)) {
            throw JsonApiException::badRequest(sprintf('filter[%s] must be a date (YYYY-MM-DD).', $name), 'filter['.$name.']');
        }

        return $value;
    }

    public static function isDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Pagination links, keeping every other query parameter of the request.
     *
     * @return array{first: string, last: string, prev: string|null, next: string|null}
     */
    public static function links(Request $request, int $number, int $size, int $total): array
    {
        $last = max(1, (int) ceil($total / $size));

        return [
            'first' => self::pageUrl($request, 1, $size),
            'last' => self::pageUrl($request, $last, $size),
            'prev' => $number > 1 ? self::pageUrl($request, $number - 1, $size) : null,
            'next' => $number < $last ? self::pageUrl($request, $number + 1, $size) : null,
        ];
    }

    private static function integer(mixed $raw, string $parameter, int $min, int $max): int
    {
        if (!\is_string($raw) || !preg_match('/^\d+$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
            throw JsonApiException::badRequest(sprintf('%s must be an integer between %d and %d.', $parameter, $min, $max), $parameter);
        }

        return (int) $raw;
    }

    private static function pageUrl(Request $request, int $number, int $size): string
    {
        $query = $request->query->all();
        $query['page'] = ['number' => (string) $number, 'size' => (string) $size];

        return $request->getPathInfo().'?'.self::encode($query);
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function encode(array $query): string
    {
        $parts = [];
        foreach ($query as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $key => $item) {
                    if (\is_string($item)) {
                        $parts[] = $name.'['.rawurlencode((string) $key).']='.rawurlencode($item);
                    }
                }
            } elseif (\is_string($value)) {
                $parts[] = rawurlencode((string) $name).'='.rawurlencode($value);
            }
        }

        return implode('&', $parts);
    }
}
