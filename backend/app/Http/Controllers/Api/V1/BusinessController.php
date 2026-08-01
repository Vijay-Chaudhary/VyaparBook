<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Services\BusinessProvisioner;
use App\Services\TokenService;
use Illuminate\Http\Request;

class BusinessController extends Controller
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly BusinessProvisioner $provisioner,
    ) {}

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'gstin' => ['nullable', 'string', 'max:15'],
            'default_language' => ['nullable', 'string', 'max:8'],
        ]);

        $userId = app('tenant.user_id');

        // Business + owner membership + trial in one transaction, shared with
        // the Blade onboarding flow (BusinessProvisioner).
        $membership = $this->provisioner->provision($userId, $data);

        $user = User::find($userId);

        return response()->json([
            'business' => $membership->business,
            'token' => $this->tokenService->issue($user, $membership),
        ], 201);
    }

    public function mine(Request $request)
    {
        // No tenant filter, deliberately: this endpoint answers "which
        // businesses am I in?", so it must span all of them. Membership carries
        // no BelongsToTenant scope for exactly this reason — it is keyed by
        // user as legitimately as by business — which is also why the query
        // tripwire excludes the memberships table.
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
