<?php
// app/Http/Controllers/Api/V1/Admin/PaymentController.php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPayment;
use App\Platform\PlatformAudit;
use App\Platform\PlatformTenantContext;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;

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
            // Pinned to $id, so RLS only surfaces this tenant's payment: a missing
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
}
