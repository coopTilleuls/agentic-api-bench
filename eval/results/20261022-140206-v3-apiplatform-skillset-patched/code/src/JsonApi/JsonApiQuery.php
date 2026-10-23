<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and validates the JSON:API query parameters of a request.
 */
final class JsonApiQuery
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    /**
     * @return array{int, int} the page number (from 1) and the page size
     */
    public static function page(Request $request): array
    {
        $page = $request->query->all()['page'] ?? [];
        if (!\is_array($page)) {
            throw JsonApiException::create(400, 'The "page" parameter must be "page[number]" and "page[size]".', null, 'page');
        }

        $number = $page['number'] ?? '1';
        if (!\is_string($number) || !ctype_digit($number) || '0' === $number[0]) {
            throw JsonApiException::create(400, 'The page number must be an integer from 1.', null, 'page[number]');
        }

        $size = $page['size'] ?? (string) self::DEFAULT_PAGE_SIZE;
        if (!\is_string($size) || !ctype_digit($size) || '0' === $size[0] || \strlen($size) > 2 || (int) $size > self::MAX_PAGE_SIZE) {
            throw JsonApiException::create(400, \sprintf('The page size must be an integer from 1 to %d.', self::MAX_PAGE_SIZE), null, 'page[size]');
        }

        // Numbers this large can only name a page past the last one; clamp them to stay within integer range.
        return [min((int) $number, 1_000_000_000_000), (int) $size];
    }

    /**
     * @param list<string> $allowed
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        $include = $request->query->all()['include'] ?? null;
        if (null === $include || '' === $include) {
            return [];
        }
        if (!\is_string($include)) {
            throw JsonApiException::create(400, 'The "include" parameter must be a comma-separated list of relationship paths.', null, 'include');
        }

        $paths = explode(',', $include);
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw JsonApiException::create(400, \sprintf('Unknown include path "%s".', $path), null, 'include');
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $allowed the sortable fields, keyed by name
     * @return list<array{string, 'ASC'|'DESC'}> field name and direction, in the order given
     */
    public static function sort(Request $request, array $allowed): array
    {
        $sort = $request->query->all()['sort'] ?? null;
        if (null === $sort || '' === $sort) {
            return [];
        }
        if (!\is_string($sort)) {
            throw JsonApiException::create(400, 'The "sort" parameter must be a comma-separated list of fields.', null, 'sort');
        }

        $sorts = [];
        foreach (explode(',', $sort) as $token) {
            $direction = str_starts_with($token, '-') ? 'DESC' : 'ASC';
            $field = str_starts_with($token, '-') ? substr($token, 1) : $token;
            if (!\array_key_exists($field, $allowed)) {
                throw JsonApiException::create(400, \sprintf('Unknown sort field "%s".', $field), null, 'sort');
            }
            $sorts[] = [$field, $direction];
        }

        return $sorts;
    }

    /**
     * The raw values of the filter[...] parameters; unknown keys are left to the caller to ignore.
     *
     * @return array<string, string>
     */
    public static function filters(Request $request): array
    {
        $filter = $request->query->all()['filter'] ?? [];
        if (!\is_array($filter)) {
            throw JsonApiException::create(400, 'The "filter" parameter must be "filter[name]=value".', null, 'filter');
        }

        foreach ($filter as $name => $value) {
            if (!\is_string($value)) {
                throw JsonApiException::create(400, \sprintf('The filter "%s" must be a single value.', $name), null, "filter[$name]");
            }
        }

        return $filter;
    }

    /**
     * @throws JsonApiException 400 when the value is not an integer
     */
    public static function integer(string $raw, string $parameter): int
    {
        if (!preg_match('/^-?[0-9]{1,18}$/', $raw)) {
            throw JsonApiException::create(400, \sprintf('The %s parameter must be an integer.', $parameter), null, $parameter);
        }

        return (int) $raw;
    }

    /**
     * @throws JsonApiException 400 when the value is not a YYYY-MM-DD date
     */
    public static function date(string $raw, string $parameter): string
    {
        if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $raw, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw JsonApiException::create(400, \sprintf('The %s parameter must be a date formatted YYYY-MM-DD.', $parameter), null, $parameter);
        }

        return $raw;
    }

    /**
     * @throws JsonApiException 400 when the value is neither true nor false
     */
    public static function boolean(string $raw, string $parameter): bool
    {
        return match ($raw) {
            'true' => true,
            'false' => false,
            default => throw JsonApiException::create(400, \sprintf('The %s parameter must be true or false.', $parameter), null, $parameter),
        };
    }

    /**
     * A resource id from a path: a positive integer that fits the database, null for anything else.
     */
    public static function id(string $raw): ?int
    {
        return preg_match('/^[1-9][0-9]{0,9}$/', $raw) && (int) $raw <= 2147483647 ? (int) $raw : null;
    }

    /**
     * Links of a page of a collection, keeping every query parameter of the request but the page number.
     *
     * @return array<string, string>
     */
    public static function pageLinks(Request $request, int $number, int $size, int $total): array
    {
        $lastPage = max(1, (int) ceil($total / $size));
        $links = [
            'self' => self::pageUrl($request, $number, $size),
            'first' => self::pageUrl($request, 1, $size),
            'last' => self::pageUrl($request, $lastPage, $size),
        ];

        if ($number > 1) {
            $links['prev'] = self::pageUrl($request, min($number - 1, $lastPage), $size);
        }
        if ($number < $lastPage) {
            $links['next'] = self::pageUrl($request, $number + 1, $size);
        }

        return $links;
    }

    private static function pageUrl(Request $request, int $number, int $size): string
    {
        $parameters = $request->query->all();
        $parameters['page'] = ['number' => $number, 'size' => $size];

        $pairs = [];
        foreach ($parameters as $name => $value) {
            if (!\is_array($value)) {
                $pairs[] = $name.'='.rawurlencode((string) $value);
                continue;
            }
            foreach ($value as $key => $item) {
                $pairs[] = "{$name}[{$key}]=".rawurlencode((string) $item);
            }
        }

        return $request->getPathInfo().'?'.implode('&', $pairs);
    }
}
