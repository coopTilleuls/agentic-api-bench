<?php

namespace App\JsonApi\V1\Books;

use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceQuery;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class BookCollectionQuery extends ResourceQuery
{
    public function rules(): array
    {
        return [
            'fields' => ['nullable', 'array', JsonApiRule::fieldSets()],
            'filter' => ['nullable', 'array'],
            'filter.title' => ['string'],
            'filter.author' => ['string'],
            'filter.minPrice' => ['integer', 'between:-2147483648,2147483647'],
            'filter.maxPrice' => ['integer', 'between:-2147483648,2147483647'],
            'filter.publishedAfter' => ['date_format:Y-m-d'],
            'filter.publishedBefore' => ['date_format:Y-m-d'],
            'filter.inStock' => [Rule::in(['true', 'false'])],
            'include' => ['nullable', 'string', JsonApiRule::includePaths()],
            'page' => ['nullable', 'array', JsonApiRule::page()],
            'page.number' => ['integer', 'min:1', 'max:2147483647'],
            'page.size' => ['integer', 'between:1,50'],
            'sort' => ['nullable', 'string', JsonApiRule::sort(['title', 'priceCents', 'publishedAt'])],
        ];
    }
}
