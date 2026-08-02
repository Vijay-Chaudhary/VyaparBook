<?php
// app/Support/TenantContext.php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where the current tenant lives.
 *
 * Under Postgres this set a transaction-scoped GUC (`app.current_tenant`) that
 * RLS policies read, so the database itself knew the tenant. MySQL has no RLS
 * and no GUCs: the tenant now exists ONLY as the container binding
 * `app('tenant.id')`, which BelongsToTenant reads. That is the whole isolation
 * mechanism — see that trait's docblock.
 */
class TenantContext
{
    /**
     * Re-point the current request at a specific business. Used by endpoints
     * (create-business, invite-accept) that must write for a business other
     * than the caller's active `tid`.
     */
    public static function switchTo(string $businessId): void
    {
        app()->bind('tenant.id', fn () => $businessId);
    }

    /**
     * Run $callback for a user with no tenant selected.
     *
     * Public auth routes (login, otp/verify) resolve a user's memberships
     * before any tenant exists. Under Postgres this set a user GUC to activate
     * the memberships policy's user_id branch; now it runs inside
     * Tenancy::withoutTenant(), because there is genuinely no tenant to bind
     * yet and the fail-closed scope would otherwise refuse the lookup.
     *
     * This is one of the four sanctioned cross-tenant paths. It is narrow: the
     * callback resolves memberships for ONE user and picks a tenant, which is
     * the step that establishes the tenant in the first place.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            app()->bind('tenant.user_id', fn () => $userId);

            return Tenancy::withoutTenant($callback);
        });
    }
}
