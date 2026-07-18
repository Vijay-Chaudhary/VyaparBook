<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Membership;
use App\Models\User;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BusinessController extends Controller
{
    public function __construct(private readonly TokenService $tokenService) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'default_language' => ['nullable', 'string', 'max:8'],
        ]);

        $userId = app('tenant.user_id');

        $membership = DB::transaction(function () use ($data, $userId) {
            $business = Business::create($data);

            // Re-point app.current_tenant at the new business before the insert:
            // the RLS WITH CHECK only admits a membership for the transaction's
            // current tenant, and the caller's tid (if any) is a different business.
            TenantContext::switchTo($business->id);
            // Bind the app-level tenant too so the BelongsToTenant scope on the
            // Subscription below is coherent with the switched-in tenant (Membership
            // is not tenant-scoped, so it needed only the GUC).
            app()->bind('tenant.id', fn () => $business->id);

            $membership = Membership::create([
                'user_id' => $userId,
                'business_id' => $business->id,
                'role' => 'owner',
            ]);

            // Every new business starts on a 14-day trial (Pro entitlement).
            app(\App\Services\SubscriptionService::class)->provisionTrial($business->id);

            return $membership;
        });

        $user = User::find($userId);

        return response()->json([
            'business' => $membership->business,
            'token' => $this->tokenService->issue($user, $membership),
        ], 201);
    }

    public function mine(Request $request)
    {
        // No tenant filter: this runs inside SetTenantContext, so
        // app.current_user_id is set and the memberships RLS policy's user_id
        // branch exposes the caller's memberships across every business —
        // which is the whole point of this endpoint.
        $memberships = Membership::with('business')
            ->where('user_id', app('tenant.user_id'))
            ->get();

        return response()->json($memberships->map(fn ($m) => [
            'business' => $m->business,
            'role' => $m->role,
        ]));
    }

    public function switch(Request $request, string $id)
    {
        $membership = Membership::where('user_id', app('tenant.user_id'))
            ->where('business_id', $id)
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'Not a member of this business.'], 403);
        }

        $user = User::find(app('tenant.user_id'));

        return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
    }
}
