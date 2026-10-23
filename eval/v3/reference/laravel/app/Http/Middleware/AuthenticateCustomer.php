<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('purchases', 'purchases/*')) {
            Auth::guard('customer')->authenticate();
            Auth::shouldUse('customer');
        }

        return $next($request);
    }
}
