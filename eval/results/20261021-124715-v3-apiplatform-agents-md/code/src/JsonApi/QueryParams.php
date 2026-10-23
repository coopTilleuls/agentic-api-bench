<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Parses and validates the JSON:API query parameters of a request.
 */
final class QueryParams
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    /**
     * @return array{number: int, size: int}
     */
    public static function page(Request $request): array
    {
        $page = self::array($request, 'page');

        return [
            'number' => self::positiveInt($page['number'] ?? null, 'page[number]', 1),
            'size' => self::sizeValue($page['size'] ?? null),
        ];
    }

    /**
     * @param list<string> $allowed the sortable fields
     * @return list<array{field: string, descending: bool}>
     */
    public static function sort(Request $request, array $allowed): array
    {
        $raw = self::string($request, 'sort');
        if (null === $raw) {
            return [];
        }

        $sorts = [];
        foreach (explode(',', $raw) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!\in_array($field, $allowed, true)) {
                throw JsonApiException::badParameter('sort', sprintf('Unknown sort field "%s".', $field));
            }
            $sorts[] = ['field' => $field, 'descending' => $descending];
        }

        return $sorts;
    }

    /**
     * @param list<string> $allowed the include paths
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        $raw = self::string($request, 'include');
        if (null === $raw) {
            return [];
        }

        $paths = explode(',', $raw);
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw JsonApiException::badParameter('include', sprintf('Unknown include path "%s".', $path));
            }
        }

        return $paths;
    }

    /**
     * Returns the raw filter values, the keys the endpoint does not know are ignored by the caller.
     *
     * @return array<string, string>
     */
    public static function filters(Request $request): array
    {
        $filters = [];
        foreach (self::array($request, 'filter') as $key => $value) {
            if (\is_string($value)) {
                $filters[(string) $key] = $value;
            } else {
                throw JsonApiException::badParameter('filter[' . $key . ']', sprintf('The filter "%s" must be a single value.', $key));
            }
        }

        return $filters;
    }

    /**
     * @param array<string, string> $filters
     */
    public static function filterInt(array $filters, string $key): ?int
    {
        if (!isset($filters[$key])) {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $filters[$key])) {
            throw JsonApiException::badParameter('filter[' . $key . ']', sprintf('"%s" must be an integer.', $key));
        }

        return (int) $filters[$key];
    }

    /**
     * @param array<string, string> $filters
     */
    public static function filterBool(array $filters, string $key): ?bool
    {
        if (!isset($filters[$key])) {
            return null;
        }

        return match ($filters[$key]) {
            'true' => true,
            'false' => false,
            default => throw JsonApiException::badParameter('filter[' . $key . ']', sprintf('"%s" must be true or false.', $key)),
        };
    }

    /**
     * @param array<string, string> $filters
     */
    public static function filterDate(array $filters, string $key): ?string
    {
        if (!isset($filters[$key])) {
            return null;
        }

        return self::date($filters[$key], 'filter[' . $key . ']');
    }

    /**
     * Validates a YYYY-MM-DD date and returns it.
     */
    public static function date(string $value, string $parameter): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw JsonApiException::badParameter($parameter, sprintf('"%s" must be a valid date (YYYY-MM-DD).', $value));
        }

        return $value;
    }

    /**
     * Builds the first, last, prev and next links, every other query parameter kept as sent.
     *
     * @return array{first: string, last: string, prev: ?string, next: ?string}
     */
    public static function pageLinks(Request $request, int $number, int $size, int $total): array
    {
        $lastNumber = max(1, (int) ceil($total / $size));
        $url = static fn (int $page): string => $request->getSchemeAndHttpHost() . $request->getPathInfo()
            . '?' . self::withPage($request, $page, $size);

        return [
            'first' => $url(1),
            'last' => $url($lastNumber),
            'prev' => $number > 1 ? $url(min($number - 1, $lastNumber)) : null,
            'next' => $number < $lastNumber ? $url($number + 1) : null,
        ];
    }

    private static function withPage(Request $request, int $number, int $size): string
    {
        $query = $request->query->all();
        $query['page'] = ['number' => $number, 'size' => $size];

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private static function sizeValue(mixed $value): int
    {
        if (null === $value) {
            return self::DEFAULT_PAGE_SIZE;
        }
        $size = self::positiveInt($value, 'page[size]', 1);
        if ($size > self::MAX_PAGE_SIZE) {
            throw JsonApiException::badParameter('page[size]', sprintf('page[size] must be between 1 and %d.', self::MAX_PAGE_SIZE));
        }

        return $size;
    }

    private static function positiveInt(mixed $value, string $parameter, int $min): int
    {
        if (null === $value) {
            return $min;
        }
        if (!\is_string($value) || !preg_match('/^\d+$/', $value) || (int) $value < $min) {
            throw JsonApiException::badParameter($parameter, sprintf('%s must be an integer of at least %d.', $parameter, $min));
        }

        return (int) $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function array(Request $request, string $key): array
    {
        if (!$request->query->has($key)) {
            return [];
        }
        $value = $request->query->all()[$key] ?? null;
        if (!\is_array($value)) {
            throw JsonApiException::badParameter($key, sprintf('"%s" must be given as %s[...].', $key, $key));
        }

        return $value;
    }

    private static function string(Request $request, string $key): ?string
    {
        if (!$request->query->has($key)) {
            return null;
        }
        $value = $request->query->all()[$key] ?? null;
        if (!\is_string($value) || '' === $value) {
            throw JsonApiException::badParameter($key, sprintf('"%s" must be a non-empty value.', $key));
        }

        return $value;
    }
}
