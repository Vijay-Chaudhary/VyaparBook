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
 * Extracted so the two callers cannot drift: re-pointing the tenant mid-request
 * is exactly the kind of thing that, duplicated, grows a subtle difference and
 * turns into a tenant-isolation bug. One home, one behaviour, one set of tests.
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

            // Re-point the tenant at the new business before the inserts: the
            // caller's existing tid (if any) is a different business, and
            // BelongsToTenant would otherwise stamp and filter the Subscription
            // against it. switchTo() binds tenant.id, which is now the whole
            // mechanism — there is no separate GUC to keep in step with it.
            TenantContext::switchTo($business->id);

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
