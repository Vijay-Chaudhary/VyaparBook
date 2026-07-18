<?php
// app/Http/Controllers/Api/V1/BillingController.php

namespace App\Http\Controllers\Api\V1;

use App\Billing\PlanCatalog;
use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Policies\BillingPolicy;
use App\Services\EntitlementService;
use App\Services\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(
        private readonly EntitlementService $entitlement,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * The billing summary: effective plan, status, trial, limits, live usage and
     * over-limit flags, plus this tenant's payment history. Owner only.
     */
    public function show(): JsonResponse
    {
        if (! (new BillingPolicy())->manage()) {
            return $this->denied();
        }

        $sub = $this->subscriptions->syncStatus(Subscription::firstOrFail());
        $plan = $this->entitlement->effectivePlan($sub);

        return response()->json([
            'plan' => $plan,
            'status' => $sub->status,
            'trial_ends_at' => $sub->trial_ends_at,
            'trial_days_left' => $this->entitlement->trialDaysLeft($sub),
            'current_period_end' => $sub->current_period_end,
            'limits' => PlanCatalog::limits($plan),
            'usage' => $this->entitlement->usage($sub),
            'over_limit' => [
                'customers' => $this->entitlement->isOverLimit($sub, 'customers'),
                'users' => $this->entitlement->isOverLimit($sub, 'users'),
            ],
            'payments' => SubscriptionPayment::orderByDesc('created_at')->get(),
        ]);
    }

    /**
     * Record a manual/UPI payment as pending — no activation here. A payment is
     * verified later by the platform (Superadmin), which calls the service seam.
     * Idempotent by (business_id, uuid): a retried record replays the same row.
     */
    public function recordPayment(Request $request): JsonResponse
    {
        if (! (new BillingPolicy())->manage()) {
            return $this->denied();
        }

        $data = $request->validate([
            'uuid' => ['nullable', 'uuid'],
            'plan' => ['required', Rule::in(['pro'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', Rule::in(['upi', 'bank', 'manual'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'period_months' => ['required', 'integer', 'min:1', 'max:24'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $uuid = $data['uuid'] ?? (string) Str::uuid();

        $existing = SubscriptionPayment::where('uuid', $uuid)->first();
        if ($existing !== null) {
            return response()->json($existing, 200);
        }

        // GST is 18% of the amount, computed at scale 2 with bcmath (never floats).
        $gst = bcmul((string) $data['amount'], '0.18', 2);

        $payment = SubscriptionPayment::create([
            'uuid' => $uuid,
            'plan' => $data['plan'],
            'amount' => $data['amount'],
            'gst_amount' => $gst,
            'mode' => $data['mode'],
            'reference' => $data['reference'] ?? null,
            'period_months' => $data['period_months'],
            'status' => 'pending',
            'note' => $data['note'] ?? null,
        ]);

        return response()->json($payment, 201);
    }

    private function denied(): JsonResponse
    {
        return response()->json(['message' => 'Only the owner can manage billing.'], 403);
    }
}
