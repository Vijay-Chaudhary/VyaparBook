<?php
// app/Services/BusinessProvisioner.php

namespace App\Services;

use App\Models\Business;
use App\Models\Membership;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates a business, its owner membership, and its trial subscription — the
 * one operation behind both "create business" surfaces (the JWT API and the
 * Blade onboarding flow).
 *
 * Extracted so the two callers cannot drift: the RLS dance below is exactly the
 * kind of thing that, duplicated, grows a subtle difference and turns into a
 * tenant-isolation bug. One home, one behaviour, one set of tests.
 */
class BusinessProvisioner
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @param  array{name: string, city?: ?string, gstin?: ?string, default_language?: ?string}  $data
     */
    public function provision(int $userId, array $data): Membership
    {
        return DB::transaction(function () use ($data, $userId) {
            $business = Business::create($data);

            // Re-point app.current_tenant at the new business before the insert:
            // the RLS WITH CHECK only admits a membership for the transaction's
            // current tenant, and the caller's existing tid (if any) is a
            // different business.
            TenantContext::switchTo($business->id);
            // Bind the app-level tenant too so the BelongsToTenant scope on the
            // Subscription is coherent with the switched-in tenant. (Membership
            // is not tenant-scoped, so it needed only the GUC.)
            app()->bind('tenant.id', fn () => $business->id);

            $membership = Membership::create([
                'user_id' => $userId,
                'business_id' => $business->id,
                'role' => 'owner',
            ]);

            // Every new business starts on a 14-day trial (Pro entitlement).
            $this->subscriptions->provisionTrial($business->id);

            return $membership;
        });
    }
}
