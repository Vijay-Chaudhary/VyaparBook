<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app('tenant.id') === null) {
            return response()->json(['message' => 'No active business selected.'], 400);
        }

        return $next($request);
    }
}
