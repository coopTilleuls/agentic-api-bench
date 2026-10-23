<?php

namespace App\JsonApi\V1\Books;

use Illuminate\Database\Eloquent\Builder;
use LaravelJsonApi\Eloquent\Drivers\StandardDriver;

class PublishedBookDriver extends StandardDriver
{
    public function query(): Builder
    {
        $query = parent::query();

        return request()->isMethodSafe() ? $query->published() : $query;
    }

    public function queryAll(): Builder
    {
        return parent::queryAll()->published();
    }
}
