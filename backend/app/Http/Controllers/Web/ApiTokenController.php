<?php
// app/Http/Controllers/Web/ApiTokenController.php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TokenService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

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

    public function store(): JsonResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // Mirrors AuthController::login — a user with exactly one business gets
        // a tenant-scoped token; anyone with several must choose, and the
        // client resolves it via /businesses/mine + /businesses/{id}/switch.
        // Read inside forUser() because memberships are RLS-scoped and no
        // tenant is set yet.
        $membership = TenantContext::forUser(
            $user->id,
            fn () => $user->memberships()->count() === 1 ? $user->memberships()->first() : null
        );

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
