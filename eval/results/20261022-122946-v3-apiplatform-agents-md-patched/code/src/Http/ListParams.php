<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the JSON:API query families (page, sort, include, filter) of a collection request.
 */
final class ListParams
{
    public const DEFAULT_SIZE = 10;
    public const MAX_SIZE = 50;

    public int $number = 1;
    public int $size = self::DEFAULT_SIZE;

    /** @var array<string, 'asc'|'desc'> field => direction, in request order */
    public array $sort = [];

    /** @var list<string> */
    public array $include = [];

    /** @var array<string, mixed> the raw filter[...] family */
    private array $filters = [];

    /**
     * @param list<string> $sortable
     * @param list<string> $includable
     */
    public static function fromRequest(Request $request, array $sortable, array $includable): self
    {
        $params = new self();
        $query = $request->query->all();

        $params->readPage($query['page'] ?? null);
        $params->readSort($query['sort'] ?? null, $sortable);
        $params->readInclude($query['include'] ?? null, $includable);

        if (isset($query['filter'])) {
            if (!\is_array($query['filter'])) {
                throw new ParameterException('filter', 'The filter parameter must be an array such as filter[title]=dune.');
            }
            $params->filters = $query['filter'];
        }

        return $params;
    }

    /**
     * Returns the raw value of filter[$name], or null when it is absent or empty.
     */
    public function raw(string $name): ?string
    {
        $value = $this->filters[$name] ?? null;
        if (null === $value || '' === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw new ParameterException('filter['.$name.']', \sprintf('filter[%s] must be a single value.', $name));
        }

        return $value;
    }

    public function integer(string $name): ?int
    {
        $value = $this->raw($name);
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match('/^-?\d{1,15}$/', $value)) {
            throw new ParameterException('filter['.$name.']', \sprintf('filter[%s] must be an integer.', $name));
        }

        return (int) $value;
    }

    public function boolean(string $name): ?bool
    {
        $value = $this->raw($name);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw new ParameterException('filter['.$name.']', \sprintf('filter[%s] must be true or false.', $name));
        }

        return 'true' === $value;
    }

    public function date(string $name): ?string
    {
        $value = $this->raw($name);
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new ParameterException('filter['.$name.']', \sprintf('filter[%s] must be a date as YYYY-MM-DD.', $name));
        }

        return $value;
    }

    private function readPage(mixed $page): void
    {
        if (null === $page) {
            return;
        }
        if (!\is_array($page)) {
            throw new ParameterException('page', 'The page parameter must be an array such as page[number]=2.');
        }
        if (\array_key_exists('number', $page)) {
            $this->number = $this->positiveInteger('page[number]', $page['number'], 1, PHP_INT_MAX);
        }
        if (\array_key_exists('size', $page)) {
            $this->size = $this->positiveInteger('page[size]', $page['size'], 1, self::MAX_SIZE);
        }
    }

    private function positiveInteger(string $parameter, mixed $value, int $min, int $max): int
    {
        if (!\is_string($value) || 1 !== preg_match('/^\d{1,18}$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw new ParameterException($parameter, \sprintf('%s must be an integer from %d to %d.', $parameter, $min, $max));
        }

        return (int) $value;
    }

    /**
     * @param list<string> $sortable
     */
    private function readSort(mixed $sort, array $sortable): void
    {
        if (null === $sort) {
            return;
        }
        if (!\is_string($sort)) {
            throw new ParameterException('sort', 'The sort parameter must be a comma separated list of fields.');
        }
        foreach (explode(',', $sort) as $item) {
            $direction = str_starts_with($item, '-') ? 'desc' : 'asc';
            $field = ltrim($item, '-');
            if (!\in_array($field, $sortable, true)) {
                throw new ParameterException('sort', \sprintf('Unknown sort field "%s". Allowed: %s.', $field, implode(', ', $sortable)));
            }
            $this->sort[$field] ??= $direction;
        }
    }

    /**
     * @param list<string> $includable
     */
    private function readInclude(mixed $include, array $includable): void
    {
        if (null === $include || '' === $include) {
            return;
        }
        if (!\is_string($include)) {
            throw new ParameterException('include', 'The include parameter must be a comma separated list of relationships.');
        }
        foreach (explode(',', $include) as $path) {
            if (!\in_array($path, $includable, true)) {
                throw new ParameterException('include', \sprintf('Unknown include path "%s". Allowed: %s.', $path, implode(', ', $includable)));
            }
            $this->include[] = $path;
        }
        $this->include = array_values(array_unique($this->include));
    }
}
