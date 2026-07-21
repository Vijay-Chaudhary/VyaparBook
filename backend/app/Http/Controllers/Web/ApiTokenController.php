<?php
// app/Http/Controllers/Web/ApiTokenController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The bridge between the two auth halves (docs/frontend-plan.md §2): exchanges
 * a valid SESSION for a short-lived JWT that the React layer uses to call the
 * JSON API and to sync.
 *
 * Deliberately a web route, not an API one. It must be authorised by the
 * session cookie, and Laravel's `api` middleware group is stateless — a
 * session-protected endpoint belongs where sessions exist.
 *
 * The token is returned in the response body for the client to hold IN MEMORY.
 * It is never written to localStorage (readable by any XSS) and never put in a
 * non-HttpOnly cookie; the page re-fetches it on load using the session.
 */
class ApiTokenController extends Controller
{
    public function __construct(private readonly TokenService $tokens) {}

    public function store(Request $request): JsonResponse
    {
        // A platform admin who launched "view as tenant" carries the read-only
        // impersonation token in their SESSION (server-side, never in the DOM or
        // a URL). Hand that back instead of minting one for the operator's own
        // memberships, so the React layer holds it in memory exactly like any
        // other token — the entire lifecycle (refresh, whoami, sync) flows
        // through the impersonation token with no client changes to how it is
        // stored. See Web\Admin\TenantActionController::impersonate.
        if (($impersonation = $request->session()->get('impersonation')) !== null) {
            $expiresAt = Carbon::parse($impersonation['expires_at']);

            if ($expiresAt->isFuture()) {
                return response()->json([
                    'token' => $impersonation['token'],
                    'token_type' => 'bearer',
                    // Real remaining life, not the original TTL, so the client
                    // refreshes against the true expiry rather than overshooting it.
                    'expires_in_minutes' => max(1, (int) ceil(Carbon::now()->diffInSeconds($expiresAt) / 60)),
                    'tenant_id' => $impersonation['tenant_id'],
                    'role' => $impersonation['role'],
                    'impersonation' => [
                        'tenant_name' => $impersonation['tenant_name'],
                        'role' => $impersonation['role'],
                        'exit_url' => route('admin.impersonation.exit'),
                    ],
                ]);
            }

            // Expired: end the impersonation and fall through to the operator's
            // own token. The support window is over; the admin is themselves again.
            $request->session()->forget('impersonation');
        }

        /** @var User $user */
        $user = auth()->user();

        $requested = $request->query('business');

        // Resolve the membership to scope the token to. Read inside forUser()
        // because memberships are RLS-scoped and no tenant is set yet.
        $membership = TenantContext::forUser($user->id, function () use ($user, $requested) {
            // An explicit ?business=… (the business switcher) scopes to that
            // business — but only if the caller is genuinely a member, so a
            // guessed id cannot mint a token into someone else's tenant.
            if ($requested !== null) {
                return $user->memberships()->where('business_id', $requested)->first();
            }

            // No preference: a single-business user is auto-scoped; a
            // multi-business user gets a tenant-less token and must pick.
            return $user->memberships()->count() === 1 ? $user->memberships()->first() : null;
        });

        // Asked for a business they do not belong to: refuse rather than
        // silently handing back a tenant-less token they did not expect.
        if ($requested !== null && $membership === null) {
            return response()->json(['message' => 'Not a member of this business.'], 403);
        }

        return response()->json([
            'token' => $this->tokens->issue($user, $membership),
            'token_type' => 'bearer',
            // Minutes, matching config('jwt.ttl'): the client schedules its own
            // refresh rather than discovering expiry through a failed sync.
            'expires_in_minutes' => (int) config('jwt.ttl'),
            'tenant_id' => $membership?->business_id,
            'role' => $membership?->role,
        ]);
    }
}
