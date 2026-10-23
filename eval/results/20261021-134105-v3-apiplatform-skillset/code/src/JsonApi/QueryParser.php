<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads the JSON:API query parameters of the current request, answering 400 on invalid values.
 */
final class QueryParser
{
    public const DEFAULT_PAGE_SIZE = 10;
    public const MAX_PAGE_SIZE = 50;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function request(): Request
    {
        return $this->requestStack->getCurrentRequest() ?? Request::create('/');
    }

    /**
     * @return array{number: int, size: int}
     */
    public function pagination(): array
    {
        $page = $this->params()['page'] ?? [];
        if (!\is_array($page)) {
            throw JsonApiException::error(400, 'The page parameter must be an object with number and size.', ['parameter' => 'page']);
        }

        return [
            'number' => $this->integer($page['number'] ?? '1', 'page[number]', 1),
            'size' => $this->integer($page['size'] ?? (string) self::DEFAULT_PAGE_SIZE, 'page[size]', 1, self::MAX_PAGE_SIZE),
        ];
    }

    /**
     * The raw query parameters: InputBag::get() refuses array values, which are the page[...] and filter[...] families.
     *
     * @return array<string, mixed>
     */
    private function params(): array
    {
        return $this->request()->query->all();
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public function include(array $allowed): array
    {
        $include = $this->params()['include'] ?? null;
        if (null === $include) {
            return [];
        }
        if (!\is_string($include)) {
            throw JsonApiException::error(400, 'The include parameter must be a comma separated list of relationship paths.', ['parameter' => 'include']);
        }

        $paths = [];
        foreach (explode(',', $include) as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw JsonApiException::error(400, sprintf('Unknown include path "%s".', $path), ['parameter' => 'include']);
            }
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    public function sort(array $allowed): array
    {
        $sort = $this->params()['sort'] ?? null;
        if (null === $sort) {
            return [];
        }
        if (!\is_string($sort)) {
            throw JsonApiException::error(400, 'The sort parameter must be a comma separated list of fields.', ['parameter' => 'sort']);
        }

        $fields = [];
        foreach (explode(',', $sort) as $field) {
            $direction = str_starts_with($field, '-') ? 'DESC' : 'ASC';
            $name = ltrim($field, '-');
            if (!\in_array($name, $allowed, true)) {
                throw JsonApiException::error(400, sprintf('Unknown sort field "%s".', $name), ['parameter' => 'sort']);
            }
            $fields[] = [$name, $direction];
        }

        return $fields;
    }

    /**
     * Filters that are not supported by the endpoint are ignored, invalid values are answered with 400.
     */
    public function text(string $name): ?string
    {
        $value = $this->filter($name);
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw $this->invalidFilter($name, 'a text');
    }

    public function integerFilter(string $name): ?int
    {
        $value = $this->filter($name);

        return null === $value ? null : $this->integer($value, 'filter['.$name.']');
    }

    public function booleanFilter(string $name): ?bool
    {
        $value = $this->filter($name);
        if (null === $value) {
            return null;
        }
        if ('true' === $value || 'false' === $value) {
            return 'true' === $value;
        }

        throw $this->invalidFilter($name, 'true or false');
    }

    public function dateFilter(string $name): ?\DateTimeImmutable
    {
        $value = $this->filter($name);

        return null === $value ? null : $this->date($value, 'filter['.$name.']');
    }

    /**
     * Parses a YYYY-MM-DD date answering 400 on the given parameter when malformed.
     */
    public function date(mixed $value, string $parameter): \DateTimeImmutable
    {
        $date = \is_string($value) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'))
            : false;
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw JsonApiException::error(400, sprintf('The %s parameter must be a date formatted as YYYY-MM-DD.', $parameter), ['parameter' => $parameter]);
        }

        return $date;
    }

    private function filter(string $name): mixed
    {
        $filters = $this->params()['filter'] ?? null;

        return \is_array($filters) ? ($filters[$name] ?? null) : null;
    }

    private function integer(mixed $value, string $parameter, int $min = \PHP_INT_MIN, int $max = \PHP_INT_MAX): int
    {
        if (!\is_string($value) || 1 !== preg_match('/^-?\d{1,18}$/', $value) || (int) $value < $min || (int) $value > $max) {
            $expected = match (true) {
                \PHP_INT_MAX === $max && \PHP_INT_MIN === $min => 'an integer',
                \PHP_INT_MAX === $max => sprintf('an integer greater than or equal to %d', $min),
                default => sprintf('an integer between %d and %d', $min, $max),
            };

            throw JsonApiException::error(400, sprintf('The %s parameter must be %s.', $parameter, $expected), ['parameter' => $parameter]);
        }

        return (int) $value;
    }

    private function invalidFilter(string $name, string $expected): JsonApiException
    {
        return JsonApiException::error(400, sprintf('The filter[%s] parameter must be %s.', $name, $expected), ['parameter' => 'filter['.$name.']']);
    }
}
