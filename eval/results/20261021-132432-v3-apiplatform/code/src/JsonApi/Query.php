<?php

namespace App\JsonApi;

use App\Http\ApiException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Parsing and validation of the JSON:API query parameters. Invalid values answer 400.
 */
final class Query
{
    public const MAX_INT = 2147483647;

    private const DEFAULT_SIZE = 10;
    private const MAX_SIZE = 50;

    /** @return array{0: int, 1: int} page number and size */
    public static function page(Request $request): array
    {
        $page = self::arrayParam($request, 'page');

        $number = self::positiveInt($page['number'] ?? '1', 'page[number]');
        $size = self::positiveInt($page['size'] ?? (string) self::DEFAULT_SIZE, 'page[size]');
        if ($size < 1 || $size > self::MAX_SIZE) {
            throw ApiException::error(400, 'page[size] must be between 1 and '.self::MAX_SIZE.'.', parameter: 'page[size]');
        }

        return [$number, $size];
    }

    /** @return int the offset of the first item of the page */
    public static function offset(int $number, int $size): int
    {
        return ($number - 1) * $size;
    }

    /**
     * @param array<string, string> $allowed field name => SQL column
     *
     * @return list<array{0: string, 1: string}> column and direction, in the order given
     */
    public static function sort(Request $request, array $allowed): array
    {
        if (!array_key_exists('sort', $request->query->all())) {
            return [];
        }
        $raw = $request->query->all()['sort'];
        if (!is_string($raw)) {
            throw ApiException::error(400, 'sort must be a comma separated list of fields.', parameter: 'sort');
        }

        $sort = [];
        foreach (explode(',', $raw) as $token) {
            $desc = str_starts_with($token, '-');
            $field = $desc ? substr($token, 1) : $token;
            if (!isset($allowed[$field])) {
                throw ApiException::error(400, 'Unknown sort field "'.$field.'".', parameter: 'sort');
            }
            $sort[] = [$allowed[$field], $desc ? 'DESC' : 'ASC'];
        }

        return $sort;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        if (!array_key_exists('include', $request->query->all())) {
            return [];
        }
        $raw = $request->query->all()['include'];
        if (!is_string($raw)) {
            throw ApiException::error(400, 'include must be a comma separated list of relationships.', parameter: 'include');
        }

        $include = explode(',', $raw);
        foreach ($include as $path) {
            if (!in_array($path, $allowed, true)) {
                throw ApiException::error(400, 'Unknown include path "'.$path.'".', parameter: 'include');
            }
        }

        return $include;
    }

    /** The value of a `filter[$key]` parameter, or null when absent. */
    public static function filter(Request $request, string $key): ?string
    {
        $filter = self::arrayParam($request, 'filter');
        if (!array_key_exists($key, $filter)) {
            return null;
        }
        if (!is_string($filter[$key])) {
            throw ApiException::error(400, 'filter['.$key.'] must be a single value.', parameter: 'filter['.$key.']');
        }

        return $filter[$key];
    }

    /** An integer filter value, clamped to the range of the integer columns. */
    public static function filterInt(Request $request, string $key): ?int
    {
        $value = self::filter($request, $key);
        if (null === $value) {
            return null;
        }
        if (!preg_match('/^-?\d{1,18}$/', $value)) {
            throw ApiException::error(400, 'filter['.$key.'] must be an integer.', parameter: 'filter['.$key.']');
        }

        return max(-self::MAX_INT, min(self::MAX_INT, (int) $value));
    }

    /** A date filter value (YYYY-MM-DD), or null when absent. */
    public static function filterDate(Request $request, string $key): ?string
    {
        $value = self::filter($request, $key);
        if (null === $value) {
            return null;
        }

        return self::date($value, 'filter['.$key.']');
    }

    /** A boolean filter value (true or false), or null when absent. */
    public static function filterBool(Request $request, string $key): ?bool
    {
        $value = self::filter($request, $key);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw ApiException::error(400, 'filter['.$key.'] must be true or false.', parameter: 'filter['.$key.']');
        }

        return 'true' === $value;
    }

    /** A required date query parameter (YYYY-MM-DD). */
    public static function requiredDate(Request $request, string $name): string
    {
        $value = $request->query->all()[$name] ?? null;
        if (null === $value) {
            throw ApiException::error(400, $name.' is required.', parameter: $name);
        }
        if (!is_string($value)) {
            throw ApiException::error(400, $name.' must be a date.', parameter: $name);
        }

        return self::date($value, $name);
    }

    /** A valid id of the database (positive integer in range), or null if it can not be one. */
    public static function id(string $raw): ?int
    {
        if (!preg_match('/^[1-9]\d{0,9}$/', $raw) || (int) $raw > self::MAX_INT) {
            return null;
        }

        return (int) $raw;
    }

    private static function date(string $value, string $name): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw ApiException::error(400, $name.' must be a date (YYYY-MM-DD).', parameter: $name);
        }

        return $value;
    }

    private static function positiveInt(mixed $value, string $name): int
    {
        if (!is_string($value) || !preg_match('/^\d{1,18}$/', $value)) {
            throw ApiException::error(400, $name.' must be an integer.', parameter: $name);
        }
        if ((int) $value < 1) {
            throw ApiException::error(400, $name.' must be at least 1.', parameter: $name);
        }

        // Pages far beyond any data are capped, so that the offset stays in range.
        return min((int) $value, 1_000_000_000);
    }

    /** @return array<string, mixed> */
    private static function arrayParam(Request $request, string $name): array
    {
        if (!array_key_exists($name, $request->query->all())) {
            return [];
        }
        $value = $request->query->all()[$name] ?? null;
        if (!is_array($value)) {
            throw ApiException::error(400, $name.' must be used as '.$name.'[...].', parameter: $name);
        }

        return $value;
    }
}
