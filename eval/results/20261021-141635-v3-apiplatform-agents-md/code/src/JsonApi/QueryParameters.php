<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads and validates the JSON:API query parameters of a request.
 */
final class QueryParameters
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    public function __construct(private readonly Request $request)
    {
    }

    /**
     * @return array{number: int, size: int}
     */
    public function page(): array
    {
        $page = $this->request->query->all()['page'] ?? [];
        if (!\is_array($page)) {
            throw JsonApiException::parameter('page', 'The page parameter must be page[number] and page[size].');
        }

        $number = $page['number'] ?? '1';
        if (!\is_string($number) || !preg_match('/^[1-9][0-9]*$/', $number)) {
            throw JsonApiException::parameter('page[number]', 'page[number] must be an integer from 1.');
        }

        $size = $page['size'] ?? (string) self::DEFAULT_PAGE_SIZE;
        if (!\is_string($size) || !preg_match('/^[0-9]+$/', $size) || (int) $size < 1 || (int) $size > self::MAX_PAGE_SIZE) {
            throw JsonApiException::parameter('page[size]', 'page[size] must be an integer from 1 to 50.');
        }

        return ['number' => (int) $number, 'size' => (int) $size];
    }

    /**
     * The sort fields in the order given: field name => descending.
     *
     * @param list<string> $allowed
     *
     * @return array<string, bool>
     */
    public function sort(array $allowed): array
    {
        $sort = $this->request->query->all()['sort'] ?? null;
        if (null === $sort) {
            return [];
        }
        if (!\is_string($sort)) {
            throw JsonApiException::parameter('sort', 'The sort parameter must be a comma separated list of fields.');
        }

        $fields = [];
        foreach (explode(',', $sort) as $item) {
            $descending = str_starts_with($item, '-');
            $field = $descending ? substr($item, 1) : $item;
            if (!\in_array($field, $allowed, true)) {
                throw JsonApiException::parameter('sort', \sprintf('Unknown sort field "%s".', $field));
            }
            $fields[$field] = $descending;
        }

        return $fields;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public function include(array $allowed): array
    {
        $include = $this->request->query->all()['include'] ?? null;
        if (null === $include) {
            return [];
        }
        if (!\is_string($include)) {
            throw JsonApiException::parameter('include', 'The include parameter must be a comma separated list of relationships.');
        }

        $paths = explode(',', $include);
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw JsonApiException::parameter('include', \sprintf('Unknown include path "%s".', $path));
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * A text filter, null when absent.
     */
    public function text(string $name): ?string
    {
        $value = $this->filter($name);
        if (null !== $value && !\is_string($value)) {
            throw JsonApiException::parameter("filter[$name]", "filter[$name] must be a text value.");
        }

        return '' === $value ? null : $value;
    }

    /**
     * An integer filter, null when absent.
     */
    public function integer(string $name): ?int
    {
        $value = $this->filter($name);
        if (null === $value) {
            return null;
        }
        if (!\is_string($value) || !preg_match('/^-?[0-9]{1,15}$/', $value)) {
            throw JsonApiException::parameter("filter[$name]", "filter[$name] must be an integer.");
        }

        return (int) $value;
    }

    /**
     * A boolean filter ("true" or "false"), null when absent.
     */
    public function boolean(string $name): ?bool
    {
        $value = $this->filter($name);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw JsonApiException::parameter("filter[$name]", "filter[$name] must be true or false.");
        }

        return 'true' === $value;
    }

    /**
     * A date filter (YYYY-MM-DD), null when absent.
     */
    public function date(string $name): ?string
    {
        $value = $this->filter($name);
        if (null === $value) {
            return null;
        }

        return self::validDate($value) ? $value : throw JsonApiException::parameter("filter[$name]", "filter[$name] must be a date as YYYY-MM-DD.");
    }

    public static function validDate(mixed $value): bool
    {
        if (!\is_string($value) || !preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * The raw value of a filter[...] parameter, null when absent.
     */
    private function filter(string $name): mixed
    {
        $filter = $this->request->query->all()['filter'] ?? [];
        if (!\is_array($filter)) {
            throw JsonApiException::parameter('filter', 'The filter parameter must be filter[name]=value.');
        }

        return $filter[$name] ?? null;
    }
}
