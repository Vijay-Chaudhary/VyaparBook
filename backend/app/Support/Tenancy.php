<?php
// app/Support/Tenancy.php

namespace App\Support;

/**
 * The only sanctioned way to run a query across tenants.
 *
 * Exactly four paths legitimately need it, and they are the audit surface that
 * replaced 23 RLS policies:
 *
 *   1. Seeders                  — run outside any request
 *   2. The superadmin console   — cross-tenant by design
 *   3. Auth before tenant selection (TenantContext::forUser)
 *   4. Inbound WhatsApp STOP    — one number, every tenant holding it
 *
 * Deliberately a named class rather than a flag, so `grep -rn withoutTenant`
 * answers "where can isolation be bypassed?" in one command.
 */
class Tenancy
{
    private static bool $suspended = false;

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withoutTenant(callable $callback): mixed
    {
        $previous = self::$suspended;
        self::$suspended = true;

        try {
            return $callback();
        } finally {
            // finally, not a trailing assignment: a throwing callback must not
            // leave isolation disabled for the rest of the request.
            self::$suspended = $previous;
        }
    }

    public static function isSuspended(): bool
    {
        return self::$suspended;
    }
}
