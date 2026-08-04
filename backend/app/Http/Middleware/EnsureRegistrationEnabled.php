<?php
// app/Http/Middleware/EnsureRegistrationEnabled.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes self-service signup when config('auth.registration_enabled') is false.
 *
 * Applied to the route rather than swapped in the routes file so the 'register'
 * route NAME always resolves: login.blade.php calls route('register') inside an
 * @if, and a conditionally-registered route would turn a stale cache into a
 * RouteNotFoundException on the login page — breaking sign-IN to close sign-UP.
 *
 * 404 rather than 403: a closed door should not advertise that it exists.
 */
class EnsureRegistrationEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('auth.registration_enabled'), 404);

        return $next($request);
    }
}
