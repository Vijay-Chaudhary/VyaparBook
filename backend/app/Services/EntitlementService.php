<?php
// app/Services/EntitlementService.php

namespace App\Services;

use App\Billing\PlanCatalog;
use App\Models\Customer;
use App\Models\Membership;
use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Resolves what a subscription is actually entitled to right now: the effective
 * plan (trial → Pro, lapsed → Free floor), live usage, over-limit checks, and
 * feature access. The single place plan/status is turned into concrete limits.
 */
class EntitlementService
{
    /**
     * The plan whose limits actually apply now. A live trial is Pro; an active
     * paid plan (period open or perpetual) is its own plan; anything lapsed —
     * past_due, read_only, canceled, expired trial/period — floors to Free.
     */
    public function effectivePlan(Subscription $sub): string
    {
        $now = Carbon::now();

        if ($sub->status === 'trialing' && $sub->trial_ends_at !== null && $sub->trial_ends_at->gte($now)) {
            return PlanCatalog::TRIAL_ENTITLEMENT;
        }

        if ($sub->status === 'active' && ($sub->current_period_end === null || $sub->current_period_end->gte($now))) {
            return $sub->plan;
        }

        return 'free';
    }

    /** read_only and canceled block writes; an expired trial does not (it floors to Free). */
    public function mayWrite(Subscription $sub): bool
    {
        return ! in_array($sub->status, ['read_only', 'canceled'], true);
    }

    /**
     * @return array{customers: int, users: int}
     */
    public function usage(Subscription $sub): array
    {
        return [
            'customers' => Customer::whereNull('archived_at')->count(),
            'users' => Membership::where('business_id', $sub->business_id)->count(),
        ];
    }

    public function isOverLimit(Subscription $sub, string $resource): bool
    {
        $plan = $this->effectivePlan($sub);

        $limit = $resource === 'users'
            ? PlanCatalog::maxUsers($plan)
            : PlanCatalog::maxCustomers($plan);

        if ($limit === null) {
            return false; // unlimited
        }

        // The *next* create would exceed: N present with limit N ⇒ already over.
        return $this->usage($sub)[$resource] >= $limit;
    }

    public function hasFeature(Subscription $sub, string $feature): bool
    {
        return PlanCatalog::has($this->effectivePlan($sub), $feature);
    }

    public function trialDaysLeft(Subscription $sub): int
    {
        $now = Carbon::now();

        if ($sub->status !== 'trialing' || $sub->trial_ends_at === null || $sub->trial_ends_at->lt($now)) {
            return 0;
        }

        return (int) ceil($now->diffInSeconds($sub->trial_ends_at) / 86400);
    }
}
