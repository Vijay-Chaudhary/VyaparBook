<?php
// app/Export/TenantExporter.php

namespace App\Export;

use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Produces a complete, portable copy of one tenant's data (PRD §13 — DPDP
 * portability and offboarding).
 *
 * Reads run with the tenant bound, on the normal application connection, rather
 * than on the SELECT-only platform connection: the export is confined by the
 * same scope that confines the tenant's own requests, so a mistake here cannot
 * pull another shop's books into a customer's export file. That is the point of
 * doing it the slow way.
 *
 * Every table read below carries its OWN business_id predicate. These are query
 * builders, not Eloquent models, so BelongsToTenant's global scope never sees
 * them — binding the tenant alone would confine nothing. Under Postgres the
 * row-level security policy caught that; MySQL has no such backstop, so the
 * predicate is the only thing standing between one shop's export and everyone
 * else's books.
 *
 * The whole export runs inside ONE transaction so the snapshot is consistent —
 * a sale written midway cannot land in sale_lines but miss sales.
 */
class TenantExporter
{
    /** Bump when the emitted structure changes in a way an importer would notice. */
    public const FORMAT_VERSION = 1;

    /**
     * Tenant-owned tables, ordered parents-before-children so the file reads in
     * dependency order and can be replayed in sequence.
     */
    private const TABLES = [
        'customers',
        'expenses',
        'reminder_logs',
        'reminder_batches',
        'invoices',
        'invoice_lines',
        'invoice_counters',
        'beats',
        'beat_customers',
        'suppliers',
        'purchases',
        'supplier_payments',
        'products',
        'pack_sizes',
        'product_packs',
        'raw_materials',
        'sales',
        'sale_lines',
        // After sales: orders.sale_id FKs into it. After product_packs (above):
        // order_lines.product_pack_id FKs into it.
        'orders',
        'order_lines',
        'payments',
        'stock_movements',
        'production_batches',
        'material_consumptions',
        'subscriptions',
        'subscription_payments',
        'invites',
    ];

    /**
     * Tenant-owned tables deliberately left OUT of the export, and why.
     *
     * Named rather than merely absent: TenantExportTest derives the expected
     * table list from the live schema, so anything carrying business_id must be
     * either exported or excluded here. A new table cannot slip out of a
     * portability export by being forgotten -- silently incomplete is a
     * compliance failure that looks like a success.
     */
    public const NOT_EXPORTED = [
        // A single (business_id, value) high-water mark for offline sync. Internal
        // machinery, not the shop's records: the number means nothing outside this
        // installation, and re-importing one would desync every device.
        'sync_sequences',
    ];

    /**
     * @return array{manifest: array<string, mixed>, data: array<string, list<array<string, mixed>>>}
     */
    public function export(string $businessId): array
    {
        DB::beginTransaction();

        try {
            TenantContext::switchTo($businessId);

            // If the id does not exist we must fail rather than emit an empty
            // export that looks like a legitimately empty tenant.
            $business = DB::table('businesses')->where('id', $businessId)->first();

            if ($business === null) {
                throw new RuntimeException("Tenant not found or not visible: {$businessId}");
            }

            $data = [];

            foreach (self::TABLES as $table) {
                $data[$table] = DB::table($table)
                    ->where('business_id', $businessId)
                    ->orderBy('id')->get()
                    ->map(fn ($row) => (array) $row)
                    ->all();
            }

            // Staff list: roles plus the identity of each member, so the owner
            // can see who had access. Joined to users because a membership on
            // its own is an opaque pair of ids.
            $data['memberships'] = DB::table('memberships as m')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.business_id', $businessId)
                ->orderBy('u.name')
                ->get(['m.id', 'm.role', 'm.created_at', 'u.id as user_id', 'u.name', 'u.email', 'u.phone'])
                ->map(fn ($row) => (array) $row)
                ->all();

            $manifest = [
                'format_version' => self::FORMAT_VERSION,
                'exported_at' => now()->toIso8601String(),
                'business' => (array) $business,
                'counts' => array_map('count', $data),
            ];

            // Read-only work: rolling back is as correct as committing and
            // leaves no trace of the export in the tenant's transaction log.
            DB::rollBack();

            return ['manifest' => $manifest, 'data' => $data];
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Write the export to $path as pretty-printed JSON.
     *
     * JSON_UNESCAPED_UNICODE keeps Devanagari readable rather than emitting
     * \uXXXX escapes — a Hindi-first tenant should be able to open their own
     * export and recognise it.
     */
    public function exportToFile(string $businessId, string $path): array
    {
        $export = $this->export($businessId);

        $json = json_encode(
            $export,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        if (@file_put_contents($path, $json) === false) {
            throw new RuntimeException("Cannot write export to: {$path}");
        }

        return $export['manifest'];
    }
}
