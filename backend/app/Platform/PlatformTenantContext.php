<?php
// app/Platform/PlatformTenantContext.php

namespace App\Platform;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Runs a platform (Superadmin) write against a single tenant.
 *
 * Platform routes carry no tenant context, and the pgsql_platform connection is
 * SELECT-only — neither can mutate a tenant's rows. A platform mutation instead
 * pins the target tenant here and writes on the normal RLS connection, so the
 * write goes THROUGH row-level security rather than around it: RLS's WITH CHECK
 * still confines every insert/update to exactly this tenant (defense in depth).
 *
 * Sets both the RLS GUC (app.current_tenant, transaction-local) and the app-level
 * tenant scope (tenant.id / tenant.user_id) so BelongsToTenant filters agree with
 * RLS. The acting admin — not a tenant member — is the current user.
 */
class PlatformTenantContext
{
    public static function actAs(string $businessId, int $adminUserId, Closure $work): mixed
    {
        return DB::transaction(function () use ($businessId, $adminUserId, $work) {
            // SET LOCAL via set_config(..., true): scoped to this transaction,
            // cleared on commit/rollback. Placeholders are illegal in bare SET.
            DB::statement("select set_config('app.current_user_id', ?, true)", [(string) $adminUserId]);
            DB::statement("select set_config('app.current_tenant', ?, true)", [(string) $businessId]);

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
