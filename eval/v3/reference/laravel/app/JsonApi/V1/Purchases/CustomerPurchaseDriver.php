<?php

namespace App\JsonApi\V1\Purchases;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use LaravelJsonApi\Eloquent\Drivers\StandardDriver;

class CustomerPurchaseDriver extends StandardDriver
{
    public function query(): Builder
    {
        return parent::query()->where('customer_id', Auth::guard('customer')->id());
    }

    public function queryAll(): Builder
    {
        return parent::queryAll()->where('customer_id', Auth::guard('customer')->id());
    }
}
