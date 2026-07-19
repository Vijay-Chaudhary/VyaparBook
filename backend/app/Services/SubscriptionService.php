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

    /**
     * Reject a pending payment — a terminal state; the correcting payment is a
     * fresh row, never an edit (mirrors the ledger's append-only rule). Idempotent
     * on replay. verified_at/verified_by stay null: they mean "verified" only, and
     * the reject decision's who/when/why is the platform audit trail's job.
     */
    public function rejectPayment(SubscriptionPayment $payment): SubscriptionPayment
    {
        if ($payment->status === 'rejected') {
            return $payment;
        }

        $payment->status = 'rejected';
        $payment->save();

        return $payment;
    }

    /**
     * Put a tenant into read_only (dunning): writes are soft-blocked, reads flow.
     * Idempotent — already read_only is a no-op. Dates are left intact so a later
     * reactivate can recompute the natural status.
     */
    public function suspend(Subscription $sub): Subscription
    {
        if ($sub->status === 'read_only') {
            return $sub;
        }

        $sub->status = 'read_only';
        $sub->save();

        return $sub;
    }

    /**
     * Lift a suspension. Only a read_only subscription changes — and it returns to
     * the status its own dates imply, never a blind 'active': a still-open paid
     * period → active, a live trial → trialing, otherwise past_due (writes flow,
     * floored to Free, so the tenant can pay). An expired trial is never resurrected.
     */
    public function reactivate(Subscription $sub): Subscription
    {
        if ($sub->status !== 'read_only') {
            return $sub;
        }

        $sub->status = $this->naturalStatus($sub);
        $sub->save();

        return $sub;
    }

    /** The status a subscription's dates imply right now, ignoring its stored status. */
    private function naturalStatus(Subscription $sub): string
    {
        $now = Carbon::now();

        if ($sub->current_period_end !== null && $sub->current_period_end->gte($now)) {
            return 'active';
        }

        if ($sub->trial_ends_at !== null && $sub->trial_ends_at->gte($now)) {
            return 'trialing';
        }

        return 'past_due';
    }
}
