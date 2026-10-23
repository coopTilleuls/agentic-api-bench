<?php

namespace App\Api;

use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the JSON:API query parameters (page, sort, include, filter) and answers 400 for invalid ones.
 */
final class ListQuery
{
    private const MAX_PAGE_SIZE = 50;
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_NUMBER = 1_000_000_000;

    /**
     * @return array{number: int, size: int}
     */
    public function page(Request $request): array
    {
        $page = $request->query->all('page');

        return [
            'number' => $this->pageInteger($page, 'number', 'page[number]', 1, self::MAX_PAGE_NUMBER, 1),
            'size' => $this->pageInteger($page, 'size', 'page[size]', 1, self::MAX_PAGE_SIZE, self::DEFAULT_PAGE_SIZE),
        ];
    }

    /**
     * @param array<string, string> $fields sort name => column expression
     *
     * @return list<array{0: string, 1: 'ASC'|'DESC'}>
     */
    public function sort(Request $request, array $fields): array
    {
        $raw = $request->query->get('sort');
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            throw ApiProblem::single(400, 'sort must be a comma-separated list of fields.', parameter: 'sort');
        }

        $sorts = [];
        foreach (explode(',', $raw) as $token) {
            $direction = 'ASC';
            if (str_starts_with($token, '-')) {
                $direction = 'DESC';
                $token = substr($token, 1);
            }
            if (!isset($fields[$token])) {
                throw ApiProblem::single(400, sprintf('Unknown sort field "%s".', $token), parameter: 'sort');
            }
            $sorts[] = [$fields[$token], $direction];
        }

        return $sorts;
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public function include(Request $request, array $allowed): array
    {
        $raw = $request->query->get('include');
        if (null === $raw || '' === $raw) {
            return [];
        }
        if (!\is_string($raw)) {
            throw ApiProblem::single(400, 'include must be a comma-separated list of relationships.', parameter: 'include');
        }

        $paths = explode(',', $raw);
        foreach ($paths as $path) {
            if (!\in_array($path, $allowed, true)) {
                throw ApiProblem::single(400, sprintf('Unknown include path "%s".', $path), parameter: 'include');
            }
        }

        return $paths;
    }

    public function filter(Request $request, string $name): ?string
    {
        $value = $request->query->all('filter')[$name] ?? null;
        if (null === $value) {
            return null;
        }
        if (!\is_string($value)) {
            throw ApiProblem::single(400, sprintf('filter[%s] must be a string.', $name), parameter: "filter[$name]");
        }

        return $value;
    }

    public function integerFilter(Request $request, string $name): ?int
    {
        $value = $this->filter($request, $name);
        if (null === $value) {
            return null;
        }
        if (!preg_match('/^-?\d{1,18}$/', $value)) {
            throw ApiProblem::single(400, sprintf('filter[%s] must be an integer.', $name), parameter: "filter[$name]");
        }

        return (int) $value;
    }

    public function booleanFilter(Request $request, string $name): ?bool
    {
        $value = $this->filter($request, $name);
        if (null === $value) {
            return null;
        }
        if ('true' !== $value && 'false' !== $value) {
            throw ApiProblem::single(400, sprintf('filter[%s] must be true or false.', $name), parameter: "filter[$name]");
        }

        return 'true' === $value;
    }

    public function dateFilter(Request $request, string $name): ?\DateTimeImmutable
    {
        $value = $this->filter($request, $name);
        if (null === $value) {
            return null;
        }

        return $this->parseDate($value) ?? throw ApiProblem::single(400, sprintf('filter[%s] must be a date (YYYY-MM-DD).', $name), parameter: "filter[$name]");
    }

    public function requiredDate(Request $request, string $name): \DateTimeImmutable
    {
        $value = $request->query->get($name);
        if (null === $value || '' === $value) {
            throw ApiProblem::single(400, sprintf('%s is required (YYYY-MM-DD).', $name), parameter: $name);
        }
        if (!\is_string($value) || null === $date = $this->parseDate($value)) {
            throw ApiProblem::single(400, sprintf('%s must be a date (YYYY-MM-DD).', $name), parameter: $name);
        }

        return $date;
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $bag
     */
    private function pageInteger(array $bag, string $key, string $name, int $min, int $max, int $default): int
    {
        if (!\array_key_exists($key, $bag)) {
            return $default;
        }

        $value = $bag[$key];
        if (!\is_string($value) || !preg_match('/^\d{1,10}$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw ApiProblem::single(400, sprintf('%s must be an integer between %d and %d.', $name, $min, $max), parameter: $name);
        }

        return (int) $value;
    }
}
