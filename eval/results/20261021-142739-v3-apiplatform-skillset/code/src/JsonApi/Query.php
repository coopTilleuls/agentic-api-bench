<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the JSON:API query parameters: page, sort, include, dates.
 */
final class Query
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    /**
     * @return array{int, int} the page number (from 1) and the page size
     */
    public static function page(Request $request): array
    {
        $page = $request->query->all('page');

        $number = self::integer($page, 'number', 'page[number]', 1, \PHP_INT_MAX) ?? 1;
        $size = self::integer($page, 'size', 'page[size]', 1, self::MAX_PAGE_SIZE) ?? self::DEFAULT_PAGE_SIZE;

        return [$number, $size];
    }

    /**
     * @param array<string, string> $fields the sortable fields, by name
     *
     * @return list<array{string, 'ASC'|'DESC'}> the field, as a key of $fields, and its direction
     */
    public static function sort(Request $request, array $fields): array
    {
        $value = $request->query->get('sort');
        if (null === $value) {
            return [];
        }
        if (!\is_string($value)) {
            throw JsonApiException::badRequest('The sort parameter must be a comma separated list of fields.', 'sort');
        }

        $sorts = [];
        foreach (explode(',', $value) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!isset($fields[$field])) {
                throw JsonApiException::badRequest(sprintf('Unknown sort field "%s".', $field), 'sort');
            }
            $sorts[] = [$field, $descending ? 'DESC' : 'ASC'];
        }

        return $sorts;
    }

    /**
     * @param list<string> $allowed the relationship names that can be included
     *
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        $value = $request->query->get('include');
        if (null === $value) {
            return [];
        }
        if (!\is_string($value)) {
            throw JsonApiException::badRequest('The include parameter must be a comma separated list of relationships.', 'include');
        }

        $includes = explode(',', $value);
        foreach ($includes as $include) {
            if (!\in_array($include, $allowed, true)) {
                throw JsonApiException::badRequest(sprintf('Unknown include path "%s".', $include), 'include');
            }
        }

        return $includes;
    }

    /**
     * Reads the value of a filter[...] parameter, or null when it is absent.
     */
    public static function filter(Request $request, string $name): ?string
    {
        $filters = $request->query->all('filter');
        if (!\array_key_exists($name, $filters)) {
            return null;
        }
        if (!\is_string($filters[$name])) {
            throw JsonApiException::badRequest(sprintf('The filter[%s] parameter must be a single value.', $name), 'filter['.$name.']');
        }

        return $filters[$name];
    }

    public static function integerFilter(Request $request, string $name): ?int
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }
        if (!preg_match('/^-?\d{1,18}$/', $value)) {
            throw JsonApiException::badRequest(sprintf('The filter[%s] parameter must be an integer.', $name), 'filter['.$name.']');
        }

        return (int) $value;
    }

    public static function booleanFilter(Request $request, string $name): ?bool
    {
        $value = self::filter($request, $name);

        return match ($value) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw JsonApiException::badRequest(sprintf('The filter[%s] parameter must be true or false.', $name), 'filter['.$name.']'),
        };
    }

    public static function dateFilter(Request $request, string $name): ?string
    {
        $value = self::filter($request, $name);
        if (null === $value) {
            return null;
        }

        return self::checkDate($value) ?? throw JsonApiException::badRequest(sprintf('The filter[%s] parameter must be a date, YYYY-MM-DD.', $name), 'filter['.$name.']');
    }

    /**
     * A required date query parameter, YYYY-MM-DD.
     */
    public static function requiredDate(Request $request, string $name): \DateTimeImmutable
    {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            throw JsonApiException::badRequest(sprintf('The %s parameter is required.', $name), $name);
        }
        if (!\is_string($value) || null === self::checkDate($value)) {
            throw JsonApiException::badRequest(sprintf('The %s parameter must be a date, YYYY-MM-DD.', $name), $name);
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    private static function checkDate(string $value): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $parameters the parameters of a nested parameter, such as page
     */
    private static function integer(array $parameters, string $key, string $name, int $min, int $max): ?int
    {
        if (!\array_key_exists($key, $parameters)) {
            return null;
        }

        $value = $parameters[$key];
        if (!\is_string($value) || !preg_match('/^\d{1,18}$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw JsonApiException::badRequest(sprintf('The %s parameter must be an integer between %d and %d.', $name, $min, $max), $name);
        }

        return (int) $value;
    }
}
