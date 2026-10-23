<?php

namespace App\Api\Query;

use App\Api\Error\JsonApiException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Reads and validates the query parameters of a request. An invalid value answers 400 naming the parameter.
 */
final class ListQuery
{
    private const DEFAULT_PAGE_SIZE = 10;
    private const MAX_PAGE_SIZE = 50;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * @return array{int, int} the page number (from 1) and the page size
     */
    public function page(): array
    {
        $number = $this->integer('page[number]', 1, 1, 999_999_999);
        $size = $this->integer('page[size]', self::DEFAULT_PAGE_SIZE, 1, self::MAX_PAGE_SIZE);

        return [$number, $size];
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    public function includes(array $allowed): array
    {
        $raw = $this->raw('include');

        if (null === $raw || '' === $raw) {
            return [];
        }

        $includes = explode(',', $raw);

        foreach ($includes as $include) {
            if (!\in_array($include, $allowed, true)) {
                throw JsonApiException::single(400, \sprintf('Unknown include path "%s".', $include), null, 'include');
            }
        }

        return $includes;
    }

    /**
     * @param array<string, string> $expressions field name => sort expression
     *
     * @return list<array{string, string}> sort expression and direction, in the order given
     */
    public function sort(array $expressions, array $default): array
    {
        $raw = $this->raw('sort');

        if (null === $raw) {
            return $default;
        }

        $sorts = [];

        foreach (explode(',', $raw) as $item) {
            $direction = str_starts_with($item, '-') ? 'DESC' : 'ASC';
            $field = ltrim($item, '-');

            if (!isset($expressions[$field])) {
                throw JsonApiException::single(400, \sprintf('Unknown sort field "%s".', $item), null, 'sort');
            }

            $sorts[] = [$expressions[$field], $direction];
        }

        return $sorts;
    }

    public function optionalInteger(string $parameter): ?int
    {
        $raw = $this->raw($parameter);

        if (null === $raw) {
            return null;
        }

        if (!preg_match('/^-?[0-9]{1,12}$/', $raw)) {
            throw JsonApiException::single(400, \sprintf('"%s" must be an integer.', $raw), null, $parameter);
        }

        return (int) $raw;
    }

    public function optionalBoolean(string $parameter): ?bool
    {
        $raw = $this->raw($parameter);

        return match ($raw) {
            null => null,
            'true' => true,
            'false' => false,
            default => throw JsonApiException::single(400, 'Must be true or false.', null, $parameter),
        };
    }

    public function date(string $parameter, bool $required = false): ?\DateTimeImmutable
    {
        $raw = $this->raw($parameter);

        if (null === $raw) {
            if ($required) {
                throw JsonApiException::single(400, 'Required parameter.', null, $parameter);
            }

            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));

        if (false === $date || $date->format('Y-m-d') !== $raw) {
            throw JsonApiException::single(400, \sprintf('"%s" must be a date as YYYY-MM-DD.', $raw), null, $parameter);
        }

        return $date;
    }

    public function optionalString(string $parameter): ?string
    {
        return $this->raw($parameter);
    }

    private function integer(string $parameter, int $default, int $min, int $max): int
    {
        $raw = $this->raw($parameter);

        if (null === $raw) {
            return $default;
        }

        if (!preg_match('/^[0-9]{1,9}$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
            throw JsonApiException::single(400, \sprintf('"%s" must be an integer between %d and %d.', $raw, $min, $max), null, $parameter);
        }

        return (int) $raw;
    }

    /**
     * Returns the value of "name" or "name[key]", or null when absent. Any value that is not a plain string is a 400.
     */
    private function raw(string $parameter): ?string
    {
        $path = preg_match('/^(\w+)\[(\w+)\]$/', $parameter, $matches) ? [$matches[1], $matches[2]] : [$parameter];
        $value = $this->request()->query->all();

        foreach ($path as $segment) {
            if (!\is_array($value) || !\array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        if (!\is_string($value)) {
            throw JsonApiException::single(400, 'Malformed parameter.', null, $parameter);
        }

        return $value;
    }

    private function request(): Request
    {
        return $this->requestStack->getCurrentRequest() ?? throw new \LogicException('No current request.');
    }
}
