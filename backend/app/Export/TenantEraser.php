<?php
// app/Export/TenantEraser.php

namespace App\Export;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Executes a DPDP erasure request (PRD §13): purge every tenant-owned row, then
 * blank the business's own identifying fields and stamp erased_at.
 *
 * The businesses row survives as a tombstone because platform_audit_logs
 * references it, and that trail is the proof the erasure happened at all.
 * Deleting the row would break the FK or orphan the evidence — so the personal
 * data goes and a dated marker stays.
 *
 * Two independent layers confine every delete, per the project's isolation rule:
 * the statements run inside the tenant's RLS context AND each carries an
 * explicit business_id filter. That redundancy is load-bearing here, not
 * ceremonial — the memberships policy also matches on app.current_user_id, so a
 * DELETE issued with a user GUC set would reach that user's memberships in
 * OTHER businesses. TenantContext::switchTo deliberately sets only the tenant.
 */
class TenantEraser
{
    /**
     * Children before parents. Derived from the live FK graph
     * (material_consumptions → production_batches, sale_lines → sales, …); a
     * wrong order surfaces as a foreign-key violation, which rolls the whole
     * erasure back rather than half-destroying a tenant.
     */
    private const DELETE_ORDER = [
        'material_consumptions',
        'stock_movements',
        'purchases',
        'sale_lines',
        'payments',
        'sales',
        'production_batches',
        'product_packs',
        'products',
        'pack_sizes',
        'raw_materials',
        'supplier_payments',
        'suppliers',
        // Before customers: reminder_logs.customer_id FKs into it.
        'reminder_logs',
        'customers',
        'expenses',
        'subscription_payments',
        'subscriptions',
        'invites',
        'memberships',
    ];

    /**
     * Nullable columns that identify the shop; cleared outright.
     */
    private const IDENTIFYING_COLUMNS = ['city', 'gstin'];

    /**
     * businesses.name is NOT NULL, so the tombstone writes a fixed marker rather
     * than nulling it. The string is identical for every erased tenant, so it
     * carries no information about the shop — and keeping the constraint means
     * the rest of the codebase can still treat name as always present.
     */
    public const ERASED_NAME = '[erased]';

    /**
     * @return array<string, int> rows deleted per table
     */
    public function erase(string $businessId): array
    {
        DB::beginTransaction();

        try {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);

            $business = DB::table('businesses')->where('id', $businessId)->first();

            if ($business === null) {
                throw new RuntimeException("Tenant not found: {$businessId}");
            }

            if ($business->erased_at !== null) {
                throw new RuntimeException("Tenant was already erased at {$business->erased_at}.");
            }

            $deleted = [];

            foreach (self::DELETE_ORDER as $table) {
                // RLS confines this already; the explicit filter is the second,
                // independent layer (and the only one guarding memberships).
                $deleted[$table] = DB::table($table)->where('business_id', $businessId)->delete();
            }

            $this->tombstone($businessId);
            $this->verify($businessId);

            DB::commit();

            return $deleted;
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Blank the shop's identifying fields in place. Nothing derived from the
     * erased data is retained — the name becomes a constant marker, not an
     * initial or a hash.
     */
    private function tombstone(string $businessId): void
    {
        DB::table('businesses')->where('id', $businessId)->update(
            array_fill_keys(self::IDENTIFYING_COLUMNS, null)
            + ['name' => self::ERASED_NAME, 'erased_at' => now()]
        );
    }

    /**
     * The verification half of "script + verification" (PRD §13). Re-counts every
     * tenant table and refuses to commit if anything survived, so a silently
     * partial erasure cannot be reported as a completed one.
     *
     * Counts MUST run on this same connection: the deletes are still inside an
     * open transaction, so any other connection would read the pre-delete
     * snapshot and report every row as a survivor. RLS is not a problem here —
     * it hides OTHER tenants' rows, never this tenant's, so a leftover row of
     * the tenant being erased is exactly what it would still show.
     */
    private function verify(string $businessId): void
    {
        $survivors = [];

        foreach (self::DELETE_ORDER as $table) {
            $count = DB::table($table)->where('business_id', $businessId)->count();

            if ($count > 0) {
                $survivors[$table] = $count;
            }
        }

        if ($survivors !== []) {
            throw new RuntimeException(
                'Erasure verification failed; rows survived in: '.json_encode($survivors)
            );
        }
    }
}
