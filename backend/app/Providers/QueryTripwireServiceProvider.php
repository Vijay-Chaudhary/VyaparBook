<?php
// app/Providers/QueryTripwireServiceProvider.php

namespace App\Providers;

use App\Support\Tenancy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Fails any test whose query touches a tenant table without scoping it.
 *
 * BelongsToTenant binds Eloquent; a raw DB::table() does not go through it, and
 * the report services use raw builders throughout. Under Postgres that gap was
 * covered by RLS. Nothing covers it now, so this catches it in CI instead.
 *
 * Registered ONLY in the test environment. It is a development tripwire, not a
 * runtime guard: string-matching SQL is too blunt to gate production traffic
 * on, and a false positive there would take the shop down.
 */
class QueryTripwireServiceProvider extends ServiceProvider
{
    /**
     * The tables that were RLS-protected under Postgres.
     *
     * 27 tables were covered by 23 policy statements — three migrations apply
     * one statement across a group of tables, so counting statements
     * undercounts the surface. Verified against the pre-migration tree with:
     *
     *   git ls-tree -r a8ad3e0 --name-only -- backend/database/migrations \
     *     | while read f; do git show "a8ad3e0:$f"; done \
     *     | grep -oE "ALTER TABLE [^ ]+ ENABLE ROW LEVEL SECURITY"
     *
     * `memberships` is deliberately EXCLUDED. Its Postgres policy had a
     * user_id branch as well as a business_id one — that is why
     * TenantContext::forUser exists — so `memberships WHERE user_id = ?` is a
     * legitimate unscoped-by-business query and would false-positive here.
     */
    private const TENANT_TABLES = [
        'customers', 'sales', 'sale_lines', 'payments', 'orders', 'order_lines',
        'products', 'pack_sizes', 'product_packs', 'raw_materials',
        'stock_movements', 'production_batches', 'material_consumptions',
        'suppliers', 'supplier_payments', 'purchases', 'expenses',
        'invoices', 'invoice_lines', 'invoice_counters',
        'beats', 'beat_customers', 'reminder_logs', 'reminder_batches',
        'subscriptions', 'subscription_payments',
    ];

    public function boot(): void
    {
        if (! $this->app->environment('testing')) {
            return;
        }

        Event::listen(function (QueryExecuted $query) {
            if (Tenancy::isSuspended()) {
                return;
            }

            $sql = strtolower($query->sql);

            // Reads only. Writes are covered by BelongsToTenant stamping
            // business_id on create, and an UPDATE/DELETE without a predicate
            // is caught by the scope on the model it came from.
            if (! str_starts_with($sql, 'select')) {
                return;
            }

            // Must be business_id used as a PREDICATE, not merely mentioned.
            // `select business_id from customers` names the column and reads
            // every tenant, so a bare str_contains() would wave it through.
            $scoped = preg_match('/business_id`?\s*(=|in\s*\(|is\s+not\s+null)/', $sql) === 1;

            foreach (self::TENANT_TABLES as $table) {
                $touches = str_contains($sql, " from `{$table}`")
                    || str_contains($sql, " join `{$table}`");

                if ($touches && ! $scoped) {
                    throw new RuntimeException(
                        "Tenant leak: query touched `{$table}` without a business_id ".
                        "predicate.\nSQL: {$query->sql}\n".
                        'Scope it, or wrap deliberately cross-tenant work in '.
                        'Tenancy::withoutTenant().'
                    );
                }
            }
        });
    }
}
