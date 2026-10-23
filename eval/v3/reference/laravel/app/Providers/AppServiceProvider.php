<?php

namespace App\Providers;

use App\JsonApi\BracketedParameterTranslator;
use App\JsonApi\IgnoreUnknownAttributesValidator;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use LaravelJsonApi\Spec\Validators\AttributesValidator;
use LaravelJsonApi\Validation\Translator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Translator::class, BracketedParameterTranslator::class);
        $this->app->bind(AttributesValidator::class, IgnoreUnknownAttributesValidator::class);
    }

    public function boot(): void
    {
        Auth::viaRequest('customer', static function (Request $request): ?Customer {
            $id = $request->header('Customer-Id');

            return is_string($id) && preg_match('/^[0-9]{1,9}$/', $id) ? Customer::find((int) $id) : null;
        });
    }
}
