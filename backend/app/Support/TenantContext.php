<?php
// app/Support/TenantContext.php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class TenantContext
{
    /**
     * Re-point the current transaction's tenant GUC at a specific business.
     * Used by endpoints (create-business, invite-accept) that must insert a
     * Membership row for a business other than the caller's active `tid`.
     */
    public static function switchTo(string $businessId): void
    {
        // NOTE: Postgres's `SET` statement grammar does not accept a bind
        // parameter (`$1`) in the value position — `SET LOCAL app.current_tenant = ?`
        // fails with a syntax error on every real Postgres connection, regardless
        // of driver. `set_config(name, value, is_local)` is the parameterizable,
        // semantically identical equivalent: the third argument `true` gives it
        // `SET LOCAL` (transaction-scoped) semantics rather than session-scoped.
        DB::statement("SELECT set_config('app.current_tenant', ?, true)", [$businessId]);
    }

    /**
     * Run $callback in a transaction scoped to a user but no tenant.
     *
     * Public auth routes (login, otp/verify) resolve a user's memberships before
     * any tenant is selected, and they run outside SetTenantContext — so
     * app.current_user_id is unset and the memberships RLS policy hides every
     * row, making membership lookups silently return nothing. Setting the GUC
     * here activates the policy's user_id branch, which exists for exactly this
     * pre-tenant-selection window.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function forUser(int $userId, callable $callback): mixed
    {
        return DB::transaction(function () use ($userId, $callback) {
            DB::statement("SELECT set_config('app.current_user_id', ?, true)", [(string) $userId]);

            return $callback();
        });
    }
}
