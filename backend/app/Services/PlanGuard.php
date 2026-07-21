<?php
// app/Services/PlanGuard.php

namespace App\Services;

use App\Models\Subscription;
use Illuminate\Http\JsonResponse;

/**
 * The one place the plan soft-block (402) is shaped, so every enforcement point
 * — customer create, user invite, the stock/production feature gate — returns
 * the identical upgrade-prompt contract. Never blocks with data loss: the write
 * is refused with a 402 and an `upgrade` flag, per PRD §8.
 */
class PlanGuard
{
    public function __construct(private readonly EntitlementService $entitlement, private readonly SubscriptionService $subscriptions) {}

    /**
     * The caller's subscription, its status transitioned if a trial/period just
     * lapsed. Fails OPEN: a tenant with no subscription row (a pre-billing/legacy
     * business, or one created outside the provisioning path) is treated as a
     * fresh trial rather than 404'd — billing must never hard-lock core operations
     * because a billing row is missing. Every business created through
     * BusinessController::store already has a real row (see SubscriptionService).
     */
    public function resolve(): Subscription
    {
        $sub = Subscription::first();

        if ($sub === null) {
            return new Subscription([
                'business_id' => app('tenant.id'),
                'plan' => 'free',
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays(14),
            ]);
        }

        return $this->subscriptions->syncStatus($sub);
    }

    public function isOverLimit(Subscription $sub, string $resource): bool
    {
        return $this->entitlement->isOverLimit($sub, $resource);
    }

    public function hasFeature(Subscription $sub, string $feature): bool
    {
        return $this->entitlement->hasFeature($sub, $feature);
    }

    public function overLimitResponse(string $resource): JsonResponse
    {
        return response()->json([
            'message' => 'Plan limit reached — upgrade to continue.',
            'code' => 'plan_limit',
            'resource' => $resource,
            'upgrade' => true,
        ], 402);
    }

    public function featureResponse(string $feature): JsonResponse
    {
        return response()->json([
            'message' => 'This feature needs a plan upgrade.',
            'code' => 'plan_limit',
            'resource' => $feature,
            'upgrade' => true,
        ], 402);
    }

    /**
     * Convenience for the stock/production controllers: returns the 402 to send
     * when the effective plan lacks stock_production, or null to proceed. Keeps
     * the identical gate line insertable after every StockPolicy::manage() check.
     */
    public function stockFeatureBlock(): ?JsonResponse
    {
        return $this->hasFeature($this->resolve(), 'stock_production')
            ? null
            : $this->featureResponse('stock_production');
    }
}
