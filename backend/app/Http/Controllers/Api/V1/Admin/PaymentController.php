<?php
// app/Http/Controllers/Api/V1/Admin/PaymentController.php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Platform\PlatformAudit;
use App\Platform\PlatformTenantContext;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform (Superadmin) console: acts on a tenant's subscription payments.
 *
 * Verifying is the payment's terminal happy path — recordPayment lodges it as
 * 'pending', the platform confirms it here, and SubscriptionService activates the
 * plan. The write runs pinned to the target tenant (PlatformTenantContext) and is
 * recorded in the platform audit trail.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /** POST /admin/tenants/{id}/payments/{paymentId}/verify — idempotent. */
    public function verify(string $id, string $paymentId): JsonResponse
    {
        $adminId = (int) auth()->id();

        $result = PlatformTenantContext::actAs($id, $adminId, function () use ($id, $paymentId) {
            // Pinned to $id, so the tenant scope only surfaces this tenant's payment: a missing
            // or cross-tenant id is indistinguishable and both 404 by design.
            $payment = SubscriptionPayment::where('id', $paymentId)->first();

            if ($payment === null) {
                return ['code' => 404, 'message' => 'Payment not found.'];
            }

            if ($payment->status === 'rejected') {
                return ['code' => 422, 'message' => 'A rejected payment cannot be verified.'];
            }

            $alreadyVerified = $payment->status === 'verified';
            $sub = $this->subscriptions->activateFromPayment($payment);

            // Audit only a real transition — a replay of an already-verified
            // payment changes nothing and leaves no second trail entry.
            if (! $alreadyVerified) {
                PlatformAudit::record('verify_payment', $id, [
                    'payment_id' => $paymentId,
                    'plan' => $sub->plan,
                    'amount' => (string) $payment->amount,
                    'current_period_end' => optional($sub->current_period_end)->toIso8601String(),
                ]);
            }

            return ['code' => 200, 'subscription' => $sub, 'payment' => $payment];
        });

        if ($result['code'] !== 200) {
            return response()->json(['message' => $result['message']], $result['code']);
        }

        return response()->json([
            'subscription' => [
                'status' => $result['subscription']->status,
                'plan' => $result['subscription']->plan,
                'current_period_end' => $result['subscription']->current_period_end,
            ],
            'payment' => [
                'id' => $result['payment']->id,
                'status' => $result['payment']->status,
                'verified_at' => $result['payment']->verified_at,
                'verified_by' => $result['payment']->verified_by,
            ],
        ]);
    }

    /** POST /admin/tenants/{id}/payments/{paymentId}/reject — idempotent. */
    public function reject(Request $request, string $id, string $paymentId): JsonResponse
    {
        $reason = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ])['reason'] ?? null;

        $adminId = (int) auth()->id();

        $result = PlatformTenantContext::actAs($id, $adminId, function () use ($id, $paymentId, $reason) {
            // Pinned to $id: a missing or cross-tenant id is invisible under the tenant scope,
            // both 404 — same confinement as verify().
            $payment = SubscriptionPayment::where('id', $paymentId)->first();

            if ($payment === null) {
                return ['code' => 404, 'message' => 'Payment not found.'];
            }

            if ($payment->status === 'verified') {
                return ['code' => 422, 'message' => 'A verified payment cannot be rejected.'];
            }

            $alreadyRejected = $payment->status === 'rejected';
            $this->subscriptions->rejectPayment($payment);

            // Audit only a real transition; a replay leaves no second trail entry.
            if (! $alreadyRejected) {
                PlatformAudit::record('reject_payment', $id, [
                    'payment_id' => $paymentId,
                    'reason' => $reason,
                ]);
            }

            return ['code' => 200, 'payment' => $payment];
        });

        if ($result['code'] !== 200) {
            return response()->json(['message' => $result['message']], $result['code']);
        }

        return response()->json([
            'payment' => [
                'id' => $result['payment']->id,
                'status' => $result['payment']->status,
            ],
        ]);
    }
}
