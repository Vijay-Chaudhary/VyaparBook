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
     * The id of a business this user owns — or, when $roles is widened, one
     * they hold any of those roles in.
     *
     * Defaults to owner only, which is what every existing owner tool means by
     * "owned". The order accept screen passes ['owner', 'admin'], because
     * approving an order is a manager's job rather than strictly the owner's.
     *
     * An explicit $requested scopes to it, but only if the caller holds one of
     * $roles there — so a guessed id cannot open someone else's data. With
     * none, the sole matching business is used. Null when nothing matches.
     *
     * Read under the user's own context (memberships are RLS-scoped, and no
     * tenant is pinned yet).
     *
     * @param  list<string>  $roles
     */
    protected function ownedBusinessId(?string $requested, array $roles = ['owner']): ?string
    {
        return TenantContext::forUser((int) auth()->id(), function () use ($requested, $roles) {
            $query = Membership::where('user_id', auth()->id())->whereIn('role', $roles);

            if ($requested !== null) {
                $query->where('business_id', $requested);
            }

            return $query->value('business_id');
        });
    }

    /**
     * Run $work with the tenant pinned — the RLS GUC, the app-level scope, and
     * the caller's real role in this business — inside one transaction.
     *
     * The role is looked up rather than hardcoded to 'owner': this trait now
     * also serves admins (the order accept screen), and stamping 'owner' onto
     * an admin's tenant.role would misreport them to every policy downstream.
     * Falls back to 'owner' only if no membership is found, which should not
     * happen given ownedBusinessId() already confirmed one.
     *
     * @template T
     * @param  callable(): T  $work
     * @return T
     */
    protected function runInTenant(string $businessId, callable $work): mixed
    {
        $role = TenantContext::forUser((int) auth()->id(), fn () => Membership::where('user_id', auth()->id())
            ->where('business_id', $businessId)->value('role')) ?? 'owner';

        return DB::transaction(function () use ($businessId, $work, $role) {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);
            app()->bind('tenant.user_id', fn () => (int) auth()->id());
            app()->bind('tenant.role', fn () => $role);

            return $work();
        });
    }
}
