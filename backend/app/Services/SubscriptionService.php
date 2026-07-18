<?php
// app/Services/SubscriptionService.php

namespace App\Services;

use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the subscription lifecycle: provisioning the trial on business creation,
 * transitioning a lapsed trial/period to past_due, and activating a plan from a
 * verified payment. activateFromPayment() is the seam the Superadmin verify
 * action calls — built and tested here, wired to no endpoint in this slice.
 */
class SubscriptionService
{
    /** Idempotent: one subscription per business, 14-day trial. */
    public function provisionTrial(string $businessId): Subscription
    {
        $existing = Subscription::where('business_id', $businessId)->first();
        if ($existing !== null) {
            return $existing;
        }

        return Subscription::create([
            'business_id' => $businessId,
            'plan' => 'free',
            'status' => 'trialing',
            'trial_ends_at' => Carbon::now()->addDays(14),
            'current_period_end' => null,
        ]);
    }

    /** Flip an expired trial or lapsed active period to past_due; otherwise untouched. */
    public function syncStatus(Subscription $sub): Subscription
    {
        $now = Carbon::now();

        $trialExpired = $sub->status === 'trialing'
            && $sub->trial_ends_at !== null && $sub->trial_ends_at->lt($now);

        $periodLapsed = $sub->status === 'active'
            && $sub->current_period_end !== null && $sub->current_period_end->lt($now);

        if ($trialExpired || $periodLapsed) {
            $sub->status = 'past_due';
            $sub->save();
        }

        return $sub;
    }

    /**
     * Activate the plan a payment paid for. Idempotent: a payment already
     * verified extends nothing on replay. Period stacks on whatever time is
     * left (max(now, current_period_end)), so an early renewal never loses days.
     */
    public function activateFromPayment(SubscriptionPayment $payment): Subscription
    {
        if ($payment->status === 'verified') {
            return Subscription::where('business_id', $payment->business_id)->firstOrFail();
        }

        return DB::transaction(function () use ($payment) {
            $sub = Subscription::where('business_id', $payment->business_id)->firstOrFail();

            $base = Carbon::now();
            if ($sub->current_period_end !== null && $sub->current_period_end->gt($base)) {
                $base = $sub->current_period_end->copy();
            }

            $sub->plan = $payment->plan;
            $sub->status = 'active';
            $sub->current_period_end = $base->copy()->addMonths($payment->period_months);
            $sub->save();

            $payment->status = 'verified';
            $payment->verified_at = Carbon::now();
            $payment->verified_by = app('tenant.user_id');
            $payment->save();

            return $sub;
        });
    }
}
