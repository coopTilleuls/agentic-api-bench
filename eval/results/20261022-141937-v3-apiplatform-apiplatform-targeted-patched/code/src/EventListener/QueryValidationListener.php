<?php

namespace App\EventListener;

use App\Exception\JsonApiException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Checks query parameters before API Platform reads them: invalid ones answer 400 naming the parameter,
 * and a malformed id in the path answers 404.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
final class QueryValidationListener
{
    private const SORT_FIELDS = ['priceCents', 'title', 'publishedAt'];

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $request->getPathInfo();
        if (preg_match('#^/(books|authors|purchases)/([^/]+)$#', $path, $matches) && (!ctype_digit($matches[2]) || \strlen($matches[2]) > 9)) {
            throw JsonApiException::one(404, \sprintf('There is no %s with id "%s".', $matches[1], $matches[2]));
        }

        if ('GET' !== $request->getMethod()) {
            return;
        }

        $errors = match (true) {
            1 === preg_match('#^/books$#', $path) => [...$this->pagination($request), ...$this->bookFilters($request), ...$this->sort($request), ...$this->include($request, ['author'])],
            1 === preg_match('#^/books/[^/]+$#', $path) => $this->include($request, ['author']),
            1 === preg_match('#^/authors$#', $path) => [...$this->pagination($request), ...$this->include($request, ['books'])],
            1 === preg_match('#^/authors/[^/]+$#', $path) => $this->include($request, ['books']),
            1 === preg_match('#^/purchases$#', $path) => [...$this->pagination($request), ...$this->include($request, [])],
            1 === preg_match('#^/purchases/[^/]+$#', $path) => $this->include($request, []),
            1 === preg_match('#^/reports/sales$#', $path) => $this->reportDates($request),
            default => [],
        };

        if ($errors) {
            throw new JsonApiException(400, $errors);
        }
    }

    /**
     * @return list<array{detail: string, source: array{parameter: string}}>
     */
    private function pagination(Request $request): array
    {
        $page = $request->query->all()['page'] ?? null;
        if (!\is_array($page)) {
            return [];
        }

        $errors = [];
        if (\array_key_exists('number', $page) && !$this->isIntegerAtLeast($page['number'], 1)) {
            $errors[] = $this->error('page[number]', 'The page number must be an integer from 1.');
        }
        if (\array_key_exists('size', $page) && !($this->isIntegerAtLeast($page['size'], 1) && (int) $page['size'] <= 50)) {
            $errors[] = $this->error('page[size]', 'The page size must be an integer from 1 to 50.');
        }

        return $errors;
    }

    /**
     * @return list<array{detail: string, source: array{parameter: string}}>
     */
    private function bookFilters(Request $request): array
    {
        $filter = $request->query->all()['filter'] ?? null;
        if (!\is_array($filter)) {
            return [];
        }

        $errors = [];
        foreach (['minPrice', 'maxPrice'] as $name) {
            if (\array_key_exists($name, $filter) && !$this->isInteger($filter[$name], true)) {
                $errors[] = $this->error("filter[$name]", 'The price must be an integer number of cents.');
            }
        }
        foreach (['publishedAfter', 'publishedBefore'] as $name) {
            if (\array_key_exists($name, $filter) && !$this->isDate($filter[$name])) {
                $errors[] = $this->error("filter[$name]", 'The date must be YYYY-MM-DD.');
            }
        }
        if (\array_key_exists('inStock', $filter) && !\in_array($filter['inStock'], ['true', 'false'], true)) {
            $errors[] = $this->error('filter[inStock]', 'The value must be true or false.');
        }
        foreach (['title', 'author'] as $name) {
            if (\array_key_exists($name, $filter) && !\is_string($filter[$name])) {
                $errors[] = $this->error("filter[$name]", 'The value must be a string.');
            }
        }

        return $errors;
    }

    /**
     * @return list<array{detail: string, source: array{parameter: string}}>
     */
    private function sort(Request $request): array
    {
        if (!$request->query->has('sort')) {
            return [];
        }

        $sort = $request->query->all()['sort'];
        if (!\is_string($sort)) {
            return [$this->error('sort', 'The sort must be a comma separated list of fields.')];
        }

        foreach (explode(',', $sort) as $field) {
            $name = str_starts_with($field, '-') ? substr($field, 1) : $field;
            if (!\in_array($name, self::SORT_FIELDS, true)) {
                return [$this->error('sort', \sprintf('"%s" is not a sortable field; use %s.', $field, implode(', ', self::SORT_FIELDS)))];
            }
        }

        return [];
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<array{detail: string, source: array{parameter: string}}>
     */
    private function include(Request $request, array $allowed): array
    {
        if (!$request->query->has('include')) {
            return [];
        }

        $include = $request->query->all()['include'];
        if (!\is_string($include)) {
            return [$this->error('include', 'The include must be a comma separated list of relationships.')];
        }

        foreach (explode(',', $include) as $relationship) {
            if (!\in_array($relationship, $allowed, true)) {
                return [$this->error('include', \sprintf('"%s" is not an includable relationship.', $relationship))];
            }
        }

        return [];
    }

    /**
     * @return list<array{detail: string, source: array{parameter: string}}>
     */
    private function reportDates(Request $request): array
    {
        $errors = [];
        foreach (['from', 'to'] as $name) {
            if (!$request->query->has($name) || !$this->isDate($request->query->all()[$name])) {
                $errors[] = $this->error($name, \sprintf('"%s" is required and must be a date, YYYY-MM-DD.', $name));
            }
        }

        if (!$errors && $request->query->get('from') > $request->query->get('to')) {
            $errors[] = $this->error('from', '"from" must not be after "to".');
        }

        return $errors;
    }

    private function isInteger(mixed $value, bool $allowNegative = false): bool
    {
        return \is_string($value) && 1 === preg_match($allowNegative ? '/^-?[0-9]{1,9}$/' : '/^[0-9]{1,9}$/', $value);
    }

    private function isIntegerAtLeast(mixed $value, int $min): bool
    {
        return $this->isInteger($value) && (int) $value >= $min;
    }

    private function isDate(mixed $value): bool
    {
        if (!\is_string($value) || 1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * @return array{detail: string, source: array{parameter: string}}
     */
    private function error(string $parameter, string $detail): array
    {
        return ['detail' => $detail, 'source' => ['parameter' => $parameter]];
    }
}
