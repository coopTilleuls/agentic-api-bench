<?php

namespace App\Http\Controllers;

use App\JsonApi\OpenApiDocument;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use LaravelJsonApi\OpenApiSpec\Facades\GeneratorFacade;

class OpenApiController
{
    public function __invoke(): JsonResponse
    {
        Auth::guard('customer')->setUser(Customer::query()->orderBy('id')->firstOrFail());

        return response()->json(OpenApiDocument::patch(json_decode(GeneratorFacade::generate('v1', 'json'), true)));
    }
}
