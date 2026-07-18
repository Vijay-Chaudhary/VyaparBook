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

    private function denied(): JsonResponse
    {
        return response()->json(['message' => 'Only the owner can manage billing.'], 403);
    }
}
