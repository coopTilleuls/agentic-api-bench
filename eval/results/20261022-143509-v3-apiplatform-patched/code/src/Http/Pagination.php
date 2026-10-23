<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * page[number] / page[size] handling and the first/last/prev/next links of a list.
 */
final class Pagination
{
    public const DEFAULT_SIZE = 10;
    public const MAX_SIZE = 50;

    private function __construct(
        public readonly int $number,
        public readonly int $size,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $page = $request->query->all()['page'] ?? [];
        if (!\is_array($page)) {
            throw QueryParams::badRequest('page', 'The page parameter must be a page[number] / page[size] family.');
        }

        $number = self::positiveInteger($page, 'number', 1, \PHP_INT_MAX, 1);
        $size = self::positiveInteger($page, 'size', 1, self::MAX_SIZE, self::DEFAULT_SIZE);

        return new self($number, $size);
    }

    public function offset(): int
    {
        return ($this->number - 1) * $this->size;
    }

    public function limit(): int
    {
        return $this->size;
    }

    public function lastPage(int $total): int
    {
        return max(1, (int) ceil($total / $this->size));
    }

    /**
     * @return array{first: string, last: string, prev?: string, next?: string}
     */
    public function links(Request $request, int $total): array
    {
        $last = $this->lastPage($total);
        $links = [
            'first' => $this->url($request, 1),
            'last' => $this->url($request, $last),
        ];
        if ($this->number > 1) {
            $links['prev'] = $this->url($request, min($this->number - 1, $last));
        }
        if ($this->number < $last) {
            $links['next'] = $this->url($request, $this->number + 1);
        }

        return $links;
    }

    /**
     * The request URL with its query (filters, sort, include, page size) kept and the page number replaced.
     */
    private function url(Request $request, int $number): string
    {
        $query = $request->query->all();
        $query['page'] = ['number' => $number, 'size' => $this->size];

        $queryString = str_replace(['%5B', '%5D'], ['[', ']'], http_build_query($query, '', '&', \PHP_QUERY_RFC3986));

        return JsonApi::baseUrl($request).$request->getPathInfo().'?'.$queryString;
    }

    /**
     * @param array<mixed> $page
     */
    private static function positiveInteger(array $page, string $key, int $min, int $max, int $default): int
    {
        $parameter = "page[$key]";
        if (!\array_key_exists($key, $page)) {
            return $default;
        }
        $value = $page[$key];
        if (!\is_string($value) || 1 !== preg_match('/^\d+$/', $value) || (int) $value < $min || (int) $value > $max) {
            throw QueryParams::badRequest($parameter, \sprintf('%s must be an integer between %d and %d.', $parameter, $min, $max));
        }

        return (int) $value;
    }
}
