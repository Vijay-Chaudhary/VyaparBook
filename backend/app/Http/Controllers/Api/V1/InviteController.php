<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invite;
use App\Models\Membership;
use App\Models\User;
use App\Policies\InvitePolicy;
use App\Services\PlanGuard;
use App\Services\TokenService;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InviteController extends Controller
{
    public function __construct(private readonly TokenService $tokenService) {}

    public function store(Request $request)
    {
        if (! (new InvitePolicy())->create()) {
            return response()->json(['message' => 'Only owners and admins can invite staff.'], 403);
        }

        $data = $request->validate([
            'role' => ['nullable', 'in:owner,admin,salesman,accountant'],
        ]);

        // Enforce the user-seat cap as a soft-block: inviting another user when the
        // plan's seats are already filled returns a 402 upgrade prompt.
        $guard = app(PlanGuard::class);
        if ($guard->isOverLimit($guard->resolve(), 'users')) {
            return $guard->overLimitResponse('users');
        }

        // business_id comes from the resolved tenant, never the {id} path segment —
        // the path is not trusted, so a mismatched id cannot invite into another business.
        $invite = Invite::create([
            'business_id' => app('tenant.id'),
            'role' => $data['role'] ?? 'salesman',
            'token' => Str::random(48),
            'invited_by' => app('tenant.user_id'),
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        return response()->json([
            'invite_link' => '/invite/accept?token=' . $invite->token,
        ], 201);
    }

    public function accept(Request $request)
    {
        $data = $request->validate(['token' => ['required', 'string']]);

        $invite = Invite::where('token', $data['token'])
            ->whereNull('redeemed_at')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (! $invite) {
            return response()->json(['message' => 'Invalid or expired invite.'], 422);
        }

        $userId = app('tenant.user_id');

        // Invite links get shared in group chats, so an existing member tapping
        // one is routine. Without this the memberships unique index raises and
        // the request 500s. The invite is deliberately left unredeemed so the
        // person it was actually meant for can still use it.
        $alreadyMember = Membership::where('user_id', $userId)
            ->where('business_id', $invite->business_id)
            ->exists();

        if ($alreadyMember) {
            return response()->json(['message' => 'Already a member of this business.'], 409);
        }

        $membership = DB::transaction(function () use ($invite, $userId) {
            TenantContext::switchTo($invite->business_id);

            $membership = Membership::create([
                'user_id' => $userId,
                'business_id' => $invite->business_id,
                'role' => $invite->role,
            ]);

            // Assigned directly rather than via update(): redeemed_by/redeemed_at
            // are not in the model's $fillable (and must not be — they are never
            // client-supplied), so mass assignment silently drops them, leaving
            // the invite redeemable by anyone holding the link until it expires.
            $invite->redeemed_by = $userId;
            $invite->redeemed_at = Carbon::now();
            $invite->save();

            return $membership;
        });

        $user = User::find($userId);

        return response()->json(['token' => $this->tokenService->issue($user, $membership)]);
    }
}
