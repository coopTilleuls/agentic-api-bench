<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and validates the JSON:API query parameters the resources understand.
 * Anything malformed raises a QueryParameterException (400).
 */
final class Query
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;
    public const MAX_PAGE_NUMBER = 2147483647;

    /**
     * @return array{int, int} the page number (from 1) and the page size
     */
    public static function pagination(Request $request): array
    {
        $page = self::family($request, 'page');

        $number = self::integer($page, 'number', 'page[number]') ?? 1;
        if ($number < 1 || $number > self::MAX_PAGE_NUMBER) {
            throw new QueryParameterException('page[number]', 'The page number must be an integer from 1.');
        }

        $size = self::integer($page, 'size', 'page[size]') ?? self::DEFAULT_PAGE_SIZE;
        if ($size < 1 || $size > self::MAX_PAGE_SIZE) {
            throw new QueryParameterException('page[size]', \sprintf('The page size must be an integer from 1 to %d.', self::MAX_PAGE_SIZE));
        }

        return [$number, $size];
    }

    /**
     * @param list<string> $allowed the include paths the resource supports
     *
     * @return list<string>
     */
    public static function includes(Request $request, array $allowed): array
    {
        $include = $request->query->get('include');
        if (null === $include) {
            return [];
        }
        if (!\is_string($include)) {
            throw new QueryParameterException('include', 'The include parameter must be a comma separated list of relationship paths.');
        }

        $paths = '' === $include ? [] : explode(',', $include);
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw new QueryParameterException('include', \sprintf('"%s" is not a relationship path that can be included.', $path));
            }
        }

        return $paths;
    }

    /**
     * @param array<string, string> $fields sort field name => column expression
     *
     * @return list<array{string, 'ASC'|'DESC'}> column expression and direction, in the order given
     */
    public static function sort(Request $request, array $fields): array
    {
        $sort = $request->query->get('sort');
        if (null === $sort) {
            return [];
        }
        if (!\is_string($sort)) {
            throw new QueryParameterException('sort', 'The sort parameter must be a comma separated list of fields.');
        }

        $orders = [];
        foreach (explode(',', $sort) as $item) {
            $direction = 'ASC';
            $field = $item;
            if (str_starts_with($item, '-')) {
                $direction = 'DESC';
                $field = substr($item, 1);
            }
            if (!isset($fields[$field])) {
                throw new QueryParameterException('sort', \sprintf('"%s" is not a field that can be sorted on.', $item));
            }
            $orders[] = [$fields[$field], $direction];
        }

        return $orders;
    }

    /**
     * The value of a filter[...] key, or null when absent or empty.
     */
    public static function filter(Request $request, string $key): ?string
    {
        return self::string(self::family($request, 'filter'), $key, \sprintf('filter[%s]', $key));
    }

    /**
     * @return array<string, mixed>
     */
    public static function family(Request $request, string $name): array
    {
        $value = $request->query->all()[$name] ?? [];
        if (!\is_array($value)) {
            throw new QueryParameterException($name, \sprintf('The %s parameter is malformed.', $name));
        }

        return $value;
    }

    public static function date(string $parameter, string $value): \DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new QueryParameterException($parameter, \sprintf('"%s" must be a date in the form YYYY-MM-DD.', $parameter));
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public static function requiredDate(Request $request, string $name): \DateTimeImmutable
    {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            throw new QueryParameterException($name, \sprintf('The %s parameter is required.', $name));
        }
        if (!\is_string($value)) {
            throw new QueryParameterException($name, \sprintf('The %s parameter must be a date.', $name));
        }

        return self::date($name, $value);
    }

    public static function boolean(string $parameter, string $value): bool
    {
        return match ($value) {
            'true' => true,
            'false' => false,
            default => throw new QueryParameterException($parameter, \sprintf('"%s" must be true or false.', $parameter)),
        };
    }

    /**
     * An integer, kept within the range of the database integer columns: a bound
     * beyond it selects the same rows as the bound at its edge.
     */
    public static function integerValue(string $parameter, string $value): int
    {
        if (!preg_match('/^-?\d{1,10}$/', $value)) {
            throw new QueryParameterException($parameter, \sprintf('"%s" must be an integer.', $parameter));
        }

        return max(-2147483648, min(2147483647, (int) $value));
    }

    /**
     * The resource id of a path, or null when it cannot be a database id.
     */
    public static function id(string $id): ?int
    {
        if (!preg_match('/^[1-9]\d{0,9}$/', $id) || (int) $id > 2147483647) {
            return null;
        }

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $family
     */
    private static function integer(array $family, string $key, string $parameter): ?int
    {
        if (!\array_key_exists($key, $family)) {
            return null;
        }
        if (!\is_string($family[$key])) {
            throw new QueryParameterException($parameter, \sprintf('"%s" must be an integer.', $parameter));
        }

        return self::integerValue($parameter, $family[$key]);
    }

    /**
     * @param array<string, mixed> $family
     */
    public static function string(array $family, string $key, string $parameter): ?string
    {
        if (!\array_key_exists($key, $family) || '' === $family[$key]) {
            return null;
        }
        if (!\is_string($family[$key])) {
            throw new QueryParameterException($parameter, \sprintf('"%s" must be a single value.', $parameter));
        }

        return $family[$key];
    }
}
