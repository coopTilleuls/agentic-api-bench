<?php

declare(strict_types=1);

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the JSON:API query parameters (page, sort, include, filter) and rejects malformed values with 400.
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
        $page = $request->query->all('page');

        return [
            'number' => self::integer($page['number'] ?? null, 'page[number]', 1, \PHP_INT_MAX, 1),
            'size' => self::integer($page['size'] ?? null, 'page[size]', 1, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE),
        ];
    }

    /**
     * @param array<string, string> $columns API field name => DQL path
     *
     * @return array<string, 'ASC'|'DESC'> DQL path => direction, in request order
     */
    public static function sort(Request $request, array $columns): array
    {
        $raw = $request->query->get('sort');

        if (null === $raw) {
            return [];
        }
        if (!\is_string($raw) || '' === $raw) {
            throw ApiException::status(400, 'The sort parameter must be a comma separated list of fields.', parameter: 'sort');
        }

        $sort = [];
        foreach (explode(',', $raw) as $field) {
            $direction = 'ASC';
            if (str_starts_with($field, '-')) {
                $direction = 'DESC';
                $field = substr($field, 1);
            }
            if (!isset($columns[$field])) {
                throw ApiException::status(400, \sprintf('Unknown sort field "%s".', $field), parameter: 'sort');
            }
            $sort[$columns[$field]] = $direction;
        }

        return $sort;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function includes(Request $request, array $allowed): array
    {
        $raw = $request->query->get('include');

        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            throw ApiException::status(400, 'The include parameter must be a comma separated list of relationships.', parameter: 'include');
        }

        $includes = explode(',', $raw);
        foreach ($includes as $include) {
            if (!\in_array($include, $allowed, true)) {
                throw ApiException::status(400, \sprintf('Unknown include path "%s".', $include), parameter: 'include');
            }
        }

        return $includes;
    }

    public static function text(Request $request, string $name): ?string
    {
        $value = self::filter($request, $name);

        return \is_string($value) ? $value : null;
    }

    public static function int(Request $request, string $name): ?int
    {
        $value = self::filter($request, $name);

        if (null === $value) {
            return null;
        }

        return self::integer($value, 'filter['.$name.']', \PHP_INT_MIN, \PHP_INT_MAX, 0, true);
    }

    public static function bool(Request $request, string $name): ?bool
    {
        $value = self::filter($request, $name);

        return match ($value) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw ApiException::status(400, 'filter['.$name.'] must be true or false.', parameter: 'filter['.$name.']'),
        };
    }

    public static function date(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = self::filter($request, $name);

        if (null === $value) {
            return null;
        }

        return self::parseDate($value) ?? throw ApiException::status(400, 'filter['.$name.'] must be a date as YYYY-MM-DD.', parameter: 'filter['.$name.']');
    }

    /**
     * A date query parameter that must be present.
     */
    public static function requiredDate(Request $request, string $name): \DateTimeImmutable
    {
        $value = $request->query->get($name);

        return (\is_string($value) ? self::parseDate($value) : null)
            ?? throw ApiException::status(400, $name.' is required and must be a date as YYYY-MM-DD.', parameter: $name);
    }

    /**
     * Fetches a path id: anything that is not a plain number is an unknown resource.
     */
    public static function id(mixed $raw): int
    {
        if (\is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (!\is_string($raw) || !ctype_digit($raw) || \strlen($raw) > 9 || (int) $raw < 1) {
            throw ApiException::status(404, 'Resource not found.');
        }

        return (int) $raw;
    }

    private static function filter(Request $request, string $name): ?string
    {
        $filters = $request->query->all('filter');

        if (!\array_key_exists($name, $filters)) {
            return null;
        }
        if (!\is_string($filters[$name])) {
            throw ApiException::status(400, 'filter['.$name.'] must be a single value.', parameter: 'filter['.$name.']');
        }

        return $filters[$name];
    }

    private static function integer(mixed $raw, string $parameter, int $min, int $max, int $default, bool $allowNegative = false): int
    {
        if (null === $raw) {
            return $default;
        }

        $pattern = $allowNegative ? '/^-?\d{1,18}$/' : '/^\d{1,18}$/';
        if (!\is_string($raw) || !preg_match($pattern, $raw)) {
            throw ApiException::status(400, $parameter.' must be an integer.', parameter: $parameter);
        }

        $value = (int) $raw;
        if ($value < $min || $value > $max) {
            $range = \PHP_INT_MAX === $max ? \sprintf('at least %d', $min) : \sprintf('between %d and %d', $min, $max);

            throw ApiException::status(400, \sprintf('%s must be %s.', $parameter, $range), parameter: $parameter);
        }

        return $value;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) || !checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            return null;
        }

        return new \DateTimeImmutable($value.' 00:00:00', new \DateTimeZone('UTC'));
    }
}
