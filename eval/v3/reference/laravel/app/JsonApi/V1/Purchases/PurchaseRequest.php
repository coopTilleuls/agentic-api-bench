<?php

namespace App\JsonApi\V1\Purchases;

use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;

class PurchaseRequest extends ResourceRequest
{
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'list'],
            'lines.*' => ['required', 'array'],
            'lines.*.bookId' => [
                'required', 'integer:strict', 'max:2147483647',
                Rule::exists('book', 'id')->where('published', true),
            ],
            'lines.*.quantity' => ['required', 'integer:strict', 'min:1', 'max:2147483647'],
        ];
    }
}
