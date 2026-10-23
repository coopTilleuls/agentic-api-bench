<?php

namespace App\JsonApi\V1\Books;

use App\Models\Book;
use LaravelJsonApi\Eloquent\Contracts\Driver;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\Scope;
use LaravelJsonApi\Eloquent\Filters\Where;
use LaravelJsonApi\Eloquent\Pagination\PagePagination;
use LaravelJsonApi\Eloquent\Schema;

class BookSchema extends Schema
{
    public static string $model = Book::class;

    protected ?array $defaultPagination = ['number' => 1];

    protected array $with = ['author'];

    public function fields(): array
    {
        return [
            ID::make()->matchAs('[0-9]{1,9}'),
            Str::make('title')->sortable(),
            Number::make('priceCents', 'price_cents')->sortable(),
            Str::make('publishedAt', 'published_at')->sortable()->readOnly(),
            Number::make('stock'),
            BelongsTo::make('author')->readOnly()->serializeUsing(
                static fn ($relation) => $relation->alwaysShowData()
            ),
        ];
    }

    public function filters(): array
    {
        return [
            Where::make('title')->using('ilike')->deserializeUsing(
                static fn (string $value): string => '%'.addcslashes($value, '\\%_').'%'
            ),
            Scope::make('author', 'byAuthorName'),
            Where::make('minPrice', 'price_cents')->gte(),
            Where::make('maxPrice', 'price_cents')->lte(),
            Where::make('publishedAfter', 'published_at')->gte(),
            Where::make('publishedBefore', 'published_at')->lte(),
            Scope::make('inStock')->asBoolean(),
        ];
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make()->withKeyName('id')->withDefaultPerPage(10);
    }

    protected function driver(): Driver
    {
        return new PublishedBookDriver($this->newInstance());
    }
}
