<?php

namespace App\JsonApi\V1\Purchases;

use LaravelJsonApi\Laravel\Http\Requests\ResourceQuery;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class PurchaseCollectionQuery extends ResourceQuery
{
    public function rules(): array
    {
        return [
            'fields' => ['nullable', 'array', JsonApiRule::fieldSets()],
            'filter' => ['nullable', 'array'],
            'include' => ['nullable', 'string', JsonApiRule::includePaths()],
            'page' => ['nullable', 'array', JsonApiRule::page()],
            'page.number' => ['integer', 'min:1', 'max:2147483647'],
            'page.size' => ['integer', 'between:1,50'],
            'sort' => ['nullable', 'string', JsonApiRule::sort()],
        ];
    }
}
