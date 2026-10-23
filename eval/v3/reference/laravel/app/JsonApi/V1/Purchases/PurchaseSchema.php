<?php

namespace App\JsonApi\V1\Purchases;

use App\Models\Purchase;
use LaravelJsonApi\Eloquent\Contracts\Driver;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\ArrayList;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Pagination\PagePagination;
use LaravelJsonApi\Eloquent\Schema;

class PurchaseSchema extends Schema
{
    public static string $model = Purchase::class;

    protected ?array $defaultPagination = ['number' => 1];

    protected $defaultSort = '-createdAt';

    protected array $with = ['lines.book'];

    public function fields(): array
    {
        return [
            ID::make()->matchAs('[0-9]{1,9}'),
            DateTime::make('createdAt', 'created_at')->sortable()->readOnly(),
            Number::make('totalCents', 'total_cents')->readOnly(),
            ArrayList::make('lines')
                ->extractUsing(static fn (Purchase $purchase) => $purchase->lines->map(static fn ($line) => [
                    'bookId' => $line->book_id,
                    'title' => $line->book->title,
                    'quantity' => $line->quantity,
                    'unitPriceCents' => $line->unit_price_cents,
                ])->all())
                ->fillUsing(static fn () => null),
        ];
    }

    protected function driver(): Driver
    {
        return new CustomerPurchaseDriver($this->newInstance());
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make()->withKeyName('id')->withDefaultPerPage(10);
    }
}
