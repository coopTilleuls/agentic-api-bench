<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Validates the JSON:API query parameters (include, sort, page, filter) of a request.
 *
 * Any malformed or unknown value answers 400 naming the offending parameter.
 */
final class QueryParser
{
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 50;
    private const MAX_INT4 = 2147483647;
    private const MIN_INT4 = -2147483648;

    /**
     * @param list<string>                                $sortable   sortable field names
     * @param list<string>                                $includable include paths allowed
     * @param array<string, 'string'|'int'|'date'|'bool'> $filters    filter name and value type
     */
    public function parseList(Request $request, array $sortable, array $includable, array $filters): ListQuery
    {
        $this->parseInclude($request, $includable);
        [$page, $size] = $this->parsePage($request);

        return new ListQuery($page, $size, $this->parseSort($request, $sortable), $this->parseFilters($request, $filters));
    }

    /**
     * @param list<string> $includable
     */
    public function parseInclude(Request $request, array $includable): void
    {
        $include = $request->query->all()['include'] ?? null;
        if (null === $include) {
            return;
        }
        if (!is_string($include)) {
            throw ApiException::single(400, 'The include parameter must be a comma-separated list of paths.', ['parameter' => 'include']);
        }

        foreach (explode(',', $include) as $path) {
            if (!in_array($path, $includable, true)) {
                throw ApiException::single(400, sprintf('Unknown include path "%s".', $path), ['parameter' => 'include']);
            }
        }
    }

    /**
     * Returns a positive integer id that fits the database integer type, or null for anything else.
     */
    public static function identifier(mixed $raw): ?int
    {
        if (is_int($raw)) {
            return $raw >= 1 && $raw <= self::MAX_INT4 ? $raw : null;
        }
        if (!is_string($raw) || 1 !== preg_match('/^[1-9][0-9]{0,9}$/', $raw)) {
            return null;
        }
        $id = (int) $raw;

        return $id <= self::MAX_INT4 ? $id : null;
    }

    /**
     * Parses a YYYY-MM-DD calendar date, or returns null.
     */
    public static function date(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return false !== $date && $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parsePage(Request $request): array
    {
        $page = $request->query->all()['page'] ?? null;
        if (null === $page) {
            return [1, self::DEFAULT_PAGE_SIZE];
        }
        if (!is_array($page)) {
            throw ApiException::single(400, 'The page parameter must be an object with "number" and "size".', ['parameter' => 'page']);
        }

        $number = $page['number'] ?? '1';
        $size = $page['size'] ?? (string) self::DEFAULT_PAGE_SIZE;

        if (!is_string($number) || 1 !== preg_match('/^[1-9][0-9]{0,8}$/', $number)) {
            throw ApiException::single(400, 'The page number must be an integer from 1.', ['parameter' => 'page[number]']);
        }
        if (!is_string($size) || 1 !== preg_match('/^[0-9]+$/', $size) || (int) $size < 1 || (int) $size > self::MAX_PAGE_SIZE) {
            throw ApiException::single(400, sprintf('The page size must be an integer from 1 to %d.', self::MAX_PAGE_SIZE), ['parameter' => 'page[size]']);
        }

        return [(int) $number, (int) $size];
    }

    /**
     * @param list<string> $sortable
     *
     * @return list<array{0: string, 1: bool}>
     */
    private function parseSort(Request $request, array $sortable): array
    {
        $sort = $request->query->all()['sort'] ?? null;
        if (null === $sort) {
            return [];
        }
        if (!is_string($sort)) {
            throw ApiException::single(400, 'The sort parameter must be a comma-separated list of fields.', ['parameter' => 'sort']);
        }

        $fields = [];
        foreach (explode(',', $sort) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!in_array($field, $sortable, true)) {
                throw ApiException::single(400, sprintf('Unknown sort field "%s".', $field), ['parameter' => 'sort']);
            }
            $fields[] = [$field, $descending];
        }

        return $fields;
    }

    /**
     * @param array<string, 'string'|'int'|'date'|'bool'> $filters
     *
     * @return array<string, string|int|bool>
     */
    private function parseFilters(Request $request, array $filters): array
    {
        $raw = $request->query->all()['filter'] ?? null;
        if (null === $raw) {
            return [];
        }
        if (!is_array($raw)) {
            throw ApiException::single(400, 'The filter parameter must be an object.', ['parameter' => 'filter']);
        }

        $parsed = [];
        foreach ($filters as $name => $type) {
            if (!array_key_exists($name, $raw)) {
                continue;
            }
            $parameter = sprintf('filter[%s]', $name);
            $value = $raw[$name];
            if (!is_string($value)) {
                throw ApiException::single(400, sprintf('The value of %s must be a single value.', $parameter), ['parameter' => $parameter]);
            }

            $parsed[$name] = match ($type) {
                'string' => $value,
                'int' => $this->int($value, $parameter),
                'date' => self::date($value)?->format('Y-m-d') ?? throw ApiException::single(400, sprintf('%s must be a date (YYYY-MM-DD).', $parameter), ['parameter' => $parameter]),
                'bool' => match ($value) {
                    'true' => true,
                    'false' => false,
                    default => throw ApiException::single(400, sprintf('%s must be true or false.', $parameter), ['parameter' => $parameter]),
                },
            };
        }

        return $parsed;
    }

    private function int(string $value, string $parameter): int
    {
        if (1 !== preg_match('/^-?[0-9]{1,10}$/', $value) || (int) $value > self::MAX_INT4 || (int) $value < self::MIN_INT4) {
            throw ApiException::single(400, sprintf('%s must be an integer.', $parameter), ['parameter' => $parameter]);
        }

        return (int) $value;
    }
}
