<?php

namespace App\JsonApi\V1\Authors;

use App\Models\Author;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Fields\Relations\HasMany;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Pagination\PagePagination;
use LaravelJsonApi\Eloquent\Schema;

class AuthorSchema extends Schema
{
    public static string $model = Author::class;

    protected ?array $defaultPagination = ['number' => 1];

    public function fields(): array
    {
        return [
            ID::make()->matchAs('[0-9]{1,9}'),
            Str::make('name')->readOnly(),
            Number::make('publishedBooks', 'books_count')->readOnly(),
            HasMany::make('books')->readOnly()->serializeUsing(
                static fn ($relation) => $relation->alwaysShowData()
            ),
        ];
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make()->withKeyName('id')->withDefaultPerPage(10);
    }
}
