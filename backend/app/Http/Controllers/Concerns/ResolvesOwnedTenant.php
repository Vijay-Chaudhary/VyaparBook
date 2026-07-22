<?php
// app/Http/Controllers/Concerns/ResolvesOwnedTenant.php

namespace App\Http\Controllers\Concerns;

use App\Models\Membership;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only web controllers share this: resolve the caller's OWNED business
 * (never one supplied by the request), then run work with that tenant pinned
 * (RLS GUC + app-level scope + owner role) in a transaction. Mirrors the
 * BillingController/OnboardingController pattern exactly.
 */
trait ResolvesOwnedTenant
{
    /**
     * The id of a business this user owns. An explicit $requested scopes to it,
     * but only if owned — so a guessed id cannot open someone else's data. With
     * none, the sole owned business is used. Null when nothing matches.
     *
     * Read under the user's own context (memberships are RLS-scoped, and no
     * tenant is pinned yet).
     */
    protected function ownedBusinessId(?string $requested): ?string
    {
        return TenantContext::forUser((int) auth()->id(), function () use ($requested) {
            $query = Membership::where('user_id', auth()->id())->where('role', 'owner');

            if ($requested !== null) {
                $query->where('business_id', $requested);
            }

            return $query->value('business_id');
        });
    }

    /**
     * Run $work with the tenant pinned — the RLS GUC, the app-level scope, and
     * the owner role — inside one transaction.
     *
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    protected function runInTenant(string $businessId, callable $work): mixed
    {
        return DB::transaction(function () use ($businessId, $work) {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);
            app()->bind('tenant.user_id', fn () => (int) auth()->id());
            app()->bind('tenant.role', fn () => 'owner');

            return $work();
        });
    }
}
