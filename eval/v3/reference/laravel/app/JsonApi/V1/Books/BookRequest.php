<?php

namespace App\JsonApi\V1\Books;

use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;

class BookRequest extends ResourceRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string'],
            'priceCents' => ['sometimes', 'required', 'integer:strict', 'min:0', 'max:2147483647'],
            'stock' => ['sometimes', 'required', 'integer:strict', 'min:0', 'max:2147483647'],
        ];
    }
}
