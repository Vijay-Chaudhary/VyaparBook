<?php
// app/Platform/PlatformTenantContext.php

namespace App\Platform;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a platform (Superadmin) write against a single tenant.
 *
 * Platform routes carry no tenant context, and the read-only platform connection
 * cannot mutate a tenant's rows. A platform mutation instead pins the target
 * tenant here and writes on the normal application connection, so the write goes
 * THROUGH the tenant scope rather than around it — BelongsToTenant stamps and
 * filters by exactly this business for the duration of $work.
 *
 * Binds tenant.id and tenant.user_id and restores whatever was bound before, so
 * a platform action cannot leave the request pinned to someone else's tenant.
 * The acting admin — not a tenant member — is the current user.
 */
class PlatformTenantContext
{
    public static function actAs(string $businessId, int $adminUserId, Closure $work): mixed
    {
        return DB::transaction(function () use ($businessId, $adminUserId, $work) {
            $prevTenantId = app('tenant.id');
            $prevUserId = app('tenant.user_id');
            app()->bind('tenant.id', fn () => $businessId);
            app()->bind('tenant.user_id', fn () => $adminUserId);

            try {
                return $work();
            } finally {
                app()->bind('tenant.id', fn () => $prevTenantId);
                app()->bind('tenant.user_id', fn () => $prevUserId);
            }
        });
    }
}
