<?php
// app/Http/Controllers/Web/Admin/TenantActionController.php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Platform\PlatformAudit;
use App\Platform\PlatformTenantContext;
use App\Services\SubscriptionService;
use App\Services\TokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The Blade console's write levers on a tenant — the session twin of the JWT
 * Admin\SubscriptionController / Admin\PaymentController / Admin\ImpersonationController.
 *
 * Every mutation goes through the SAME seams as the API: PlatformTenantContext
 * pins the target tenant and writes on the RLS connection (so RLS's WITH CHECK
 * still confines the write — defense in depth, never around it), the shared
 * SubscriptionService owns the state transitions, and PlatformAudit records the
 * trail. The web layer only chooses the surface (redirect + flash vs JSON); the
 * behaviour has one implementation.
 *
 * Session-gated to platform admins by platform_admin.web.
 */
class TenantActionController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly TokenService $tokens,
    ) {}

    /** Drop a tenant into read_only (dunning) — idempotent. */
    public function suspend(Request $request, string $id): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        $error = $this->transition(
            $id,
            fn (Subscription $sub) => $this->subscriptions->suspend($sub),
            'suspend_tenant',
            ['reason' => $reason],
        );

        return $this->backTo($id, $error, 'suspended');
    }

    /** Lift a suspension — idempotent; returns to the status the dates imply. */
    public function reactivate(string $id): RedirectResponse
    {
        $error = $this->transition(
            $id,
            fn (Subscription $sub) => $this->subscriptions->reactivate($sub),
            'reactivate_tenant',
            [],
        );

        return $this->backTo($id, $error, 'reactivated');
    }

    /** Confirm a pending payment — activates the plan via the service — idempotent. */
    public function verifyPayment(string $id, string $paymentId): RedirectResponse
    {
        $adminId = (int) auth()->id();

        $error = PlatformTenantContext::actAs($id, $adminId, function () use ($id, $paymentId) {
            $payment = SubscriptionPayment::where('id', $paymentId)->first();

            if ($payment === null) {
                return 'not_found';
            }
            if ($payment->status === 'rejected') {
                return 'verify_rejected';
            }

            $alreadyVerified = $payment->status === 'verified';
            $sub = $this->subscriptions->activateFromPayment($payment);

            if (! $alreadyVerified) {
                PlatformAudit::record('verify_payment', $id, [
                    'payment_id' => $paymentId,
                    'plan' => $sub->plan,
                    'amount' => (string) $payment->amount,
                    'current_period_end' => optional($sub->current_period_end)->toIso8601String(),
                ]);
            }

            return null;
        });

        return $this->backTo($id, $error, 'payment_verified');
    }

    /** Reject a pending payment — terminal; the correction is a fresh row — idempotent. */
    public function rejectPayment(Request $request, string $id, string $paymentId): RedirectResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:255']])['reason'] ?? null;

        $adminId = (int) auth()->id();

        $error = PlatformTenantContext::actAs($id, $adminId, function () use ($id, $paymentId, $reason) {
            $payment = SubscriptionPayment::where('id', $paymentId)->first();

            if ($payment === null) {
                return 'not_found';
            }
            if ($payment->status === 'verified') {
                return 'reject_verified';
            }

            $alreadyRejected = $payment->status === 'rejected';
            $this->subscriptions->rejectPayment($payment);

            if (! $alreadyRejected) {
                PlatformAudit::record('reject_payment', $id, [
                    'payment_id' => $paymentId,
                    'reason' => $reason,
                ]);
            }

            return null;
        });

        return $this->backTo($id, $error, 'payment_rejected');
    }

    /**
     * "View as tenant": mint a short-lived READ-ONLY token, stash it in the
     * operator's session, and drop them into /app rendered as this tenant. The
     * token lives ONLY in the server-side session — never a URL, never the DOM —
     * and the session→JWT bridge (ApiTokenController) hands it to the React layer
     * on boot. Role-existence is checked on the BYPASSRLS connection exactly as
     * the API does, and every launch is audited.
     */
    public function impersonate(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['nullable', Rule::in(['owner', 'admin', 'salesman', 'accountant'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $platform = DB::connection('mysql_platform');

        $business = $platform->table('businesses')->where('id', $id)->first(['id', 'name']);
        if ($business === null) {
            return $this->backTo($id, 'not_found', null);
        }

        // Widest view by default; a narrower role reproduces a specific complaint.
        $role = $data['role'] ?? 'owner';

        $roleExists = $platform->table('memberships')
            ->where('business_id', $id)->where('role', $role)->exists();

        if (! $roleExists) {
            return $this->backTo($id, 'role_absent', null);
        }

        $admin = User::findOrFail(auth()->id());
        $ttl = 30;
        $token = $this->tokens->issueImpersonation($admin, $id, $role, $ttl);

        PlatformAudit::record('impersonate_tenant', $id, [
            'role' => $role,
            'reason' => $data['reason'] ?? null,
            'ttl_minutes' => $ttl,
        ]);

        // Session, not a flash or a query string: the token is a bearer
        // credential and must not land in history, logs or a shareable link.
        $request->session()->put('impersonation', [
            'token' => $token,
            'tenant_id' => $id,
            'tenant_name' => $business->name,
            'role' => $role,
            'expires_at' => now()->addMinutes($ttl)->toIso8601String(),
        ]);

        return redirect()->route('app');
    }

    /**
     * End a "view as tenant" session: drop the impersonation token and return the
     * operator to the tenant's drill-down. The local cache is deleted client-side
     * before this fires (see the app banner), so no tenant data is left behind on
     * the operator's device.
     */
    public function exitImpersonation(Request $request): RedirectResponse
    {
        $tenantId = $request->session()->get('impersonation')['tenant_id'] ?? null;

        $request->session()->forget('impersonation');

        return $tenantId !== null
            ? redirect()->route('admin.console.show', $tenantId)
            : redirect()->route('admin.console');
    }

    /* --- helpers --------------------------------------------------- */

    /**
     * Load the tenant's subscription pinned to that tenant, apply the transition, and
     * audit only a real status change — the shared shell for suspend/reactivate,
     * mirroring Admin\SubscriptionController::transition.
     *
     * @param  callable(Subscription): Subscription  $apply
     * @param  array<string, mixed>  $metadata
     * @return string|null  an error key, or null on success
     */
    private function transition(string $id, callable $apply, string $action, array $metadata): ?string
    {
        $adminId = (int) auth()->id();

        return PlatformTenantContext::actAs($id, $adminId, function () use ($id, $apply, $action, $metadata) {
            $sub = Subscription::where('business_id', $id)->first();

            if ($sub === null) {
                return 'no_subscription';
            }

            $before = $sub->status;
            $sub = $apply($sub);

            if ($sub->status !== $before) {
                PlatformAudit::record($action, $id, $metadata + ['from' => $before, 'to' => $sub->status]);
            }

            return null;
        });
    }

    /** Redirect back to the drill-down with either an error or a success flash. */
    private function backTo(string $id, ?string $error, ?string $success): RedirectResponse
    {
        $redirect = redirect()->route('admin.console.show', $id);

        return $error !== null
            ? $redirect->with('console_error', $error)
            : $redirect->with('console_status', $success);
    }
}
