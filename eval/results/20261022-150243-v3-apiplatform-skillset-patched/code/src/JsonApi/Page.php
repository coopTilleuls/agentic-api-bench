<?php

namespace App\JsonApi;

use Symfony\Component\HttpFoundation\Request;

/**
 * Pagination parameters page[number] and page[size], with the links of a collection document.
 */
final class Page
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
        $page = $request->query->all('page');

        $number = isset($page['number']) ? Input::digits($page['number'], 'page[number]', 1, 1_000_000_000) : 1;
        $size = isset($page['size']) ? Input::digits($page['size'], 'page[size]', 1, self::MAX_SIZE) : self::DEFAULT_SIZE;

        return new self($number, $size);
    }

    public function offset(): int
    {
        return ($this->number - 1) * $this->size;
    }

    /**
     * Returns the collection links, each one keeping every other query parameter of the request.
     *
     * @return array<string, string|null>
     */
    public function links(Request $request, int $total): array
    {
        $last = max(1, (int) ceil($total / $this->size));

        $links = [
            'self' => $this->url($request, $this->number),
            'first' => $this->url($request, 1),
            'last' => $this->url($request, $last),
            'prev' => null,
            'next' => null,
        ];
        if ($this->number > 1) {
            $links['prev'] = $this->url($request, $this->number - 1);
        }
        if ($this->number < $last) {
            $links['next'] = $this->url($request, $this->number + 1);
        }

        return $links;
    }

    private function url(Request $request, int $number): string
    {
        $query = $request->query->all();
        $query['page'] = ['number' => (string) $number, 'size' => (string) $this->size];

        return $request->getPathInfo().'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
