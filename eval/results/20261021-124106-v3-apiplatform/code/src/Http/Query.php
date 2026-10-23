<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and validates the query parameters of list and detail requests.
 */
final class Query
{
    /** @return array<string, mixed> the members of a family such as `filter` or `page` */
    public static function family(Request $request, string $family): array
    {
        $value = $request->query->all()[$family] ?? [];
        if (!is_array($value)) {
            throw ApiException::one(400, "$family must be used as $family[name]=value.", null, $family);
        }

        return $value;
    }

    /** @param array<string, mixed> $family */
    public static function string(array $family, string $name, string $key): ?string
    {
        if (!array_key_exists($key, $family)) {
            return null;
        }
        if (!is_string($family[$key])) {
            throw ApiException::one(400, "$name[$key] must be a single value.", null, "$name[$key]");
        }

        return $family[$key];
    }

    public static function integer(string $value, string $parameter): int
    {
        if (1 !== preg_match('/^-?\d{1,9}$/', $value)) {
            throw ApiException::one(400, "$parameter must be an integer.", null, $parameter);
        }

        return (int) $value;
    }

    public static function boolean(string $value, string $parameter): bool
    {
        return match ($value) {
            'true' => true,
            'false' => false,
            default => throw ApiException::one(400, "$parameter must be true or false.", null, $parameter),
        };
    }

    /** @return string the date as YYYY-MM-DD */
    public static function date(string $value, string $parameter): string
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw ApiException::one(400, "$parameter must be a date as YYYY-MM-DD.", null, $parameter);
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        if (!checkdate($month, $day, $year)) {
            throw ApiException::one(400, "$parameter is not a valid date.", null, $parameter);
        }

        return $value;
    }

    /**
     * @param array<string, string> $allowed sort field => column
     *
     * @return list<array{0: string, 1: string}> column and direction pairs, in the order given
     */
    public static function sort(Request $request, array $allowed): array
    {
        $value = $request->query->get('sort');
        if (null === $value) {
            return [];
        }
        if (!is_string($value)) {
            throw ApiException::one(400, 'sort must be a comma separated list of fields.', null, 'sort');
        }

        $orders = [];
        foreach (explode(',', $value) as $item) {
            $direction = str_starts_with($item, '-') ? 'DESC' : 'ASC';
            $field = ltrim($item, '-');
            if (!isset($allowed[$field])) {
                throw ApiException::one(400, "Unknown sort field \"$field\".", null, 'sort');
            }
            $orders[] = [$allowed[$field], $direction];
        }

        return $orders;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public static function include(Request $request, array $allowed): array
    {
        $value = $request->query->get('include');
        if (null === $value || '' === $value) {
            return [];
        }
        if (!is_string($value)) {
            throw ApiException::one(400, 'include must be a comma separated list of paths.', null, 'include');
        }

        $paths = explode(',', $value);
        foreach ($paths as $path) {
            if (!in_array($path, $allowed, true)) {
                throw ApiException::one(400, "Unknown include path \"$path\".", null, 'include');
            }
        }

        return $paths;
    }

    /** @return array{0: int, 1: int} page number and page size */
    public static function page(Request $request): array
    {
        $page = self::family($request, 'page');
        $number = self::string($page, 'page', 'number');
        $size = self::string($page, 'page', 'size');

        $pageNumber = 1;
        if (null !== $number) {
            if (1 !== preg_match('/^\d{1,9}$/', $number) || (int) $number < 1) {
                throw ApiException::one(400, 'page[number] must be an integer from 1.', null, 'page[number]');
            }
            $pageNumber = (int) $number;
        }

        $pageSize = JsonApi::DEFAULT_SIZE;
        if (null !== $size) {
            if (1 !== preg_match('/^\d{1,9}$/', $size) || (int) $size < 1 || (int) $size > JsonApi::MAX_SIZE) {
                throw ApiException::one(400, 'page[size] must be an integer from 1 to '.JsonApi::MAX_SIZE.'.', null, 'page[size]');
            }
            $pageSize = (int) $size;
        }

        return [$pageNumber, $pageSize];
    }

    /** Parses a path identifier; null when it is not a valid id. */
    public static function id(string $value): ?int
    {
        if (1 !== preg_match('/^[1-9]\d{0,8}$/', $value)) {
            return null;
        }

        return (int) $value;
    }
}
