<?php
// app/Http/Middleware/EnforceActivePlan.php

namespace App\Http\Middleware;

use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The read-only (dunning) gate: when a tenant's subscription is read_only, its
 * domain *writes* are paused with a 402 until payment — but reads still flow, so
 * the shop can see its khata while it pays. A soft-block, never data loss (PRD §8).
 *
 * Registered as 'plan.gate' inside require.tenant, so the tenant is already
 * pinned (SetTenantContext) when the subscription is read here.
 */
class EnforceActivePlan
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request); // GET/HEAD always pass
        }

        $sub = Subscription::first();

        if ($sub !== null && $sub->status === 'read_only') {
            return response()->json([
                'message' => 'Subscription is past due — writes are paused until payment.',
                'code' => 'read_only',
                'upgrade' => true,
            ], 402);
        }

        return $next($request);
    }
}
