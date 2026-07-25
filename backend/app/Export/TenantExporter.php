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
 * Reads run under the tenant's own RLS context rather than on the BYPASSRLS
 * platform connection: the export is confined by the same policy that confines
 * the tenant's own requests, so a mistake here cannot pull another shop's books
 * into a customer's export file. That is the point of doing it the slow way.
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
        'payments',
        'stock_movements',
        'production_batches',
        'material_consumptions',
        'subscriptions',
        'subscription_payments',
        'invites',
    ];

    /**
     * @return array{manifest: array<string, mixed>, data: array<string, list<array<string, mixed>>>}
     */
    public function export(string $businessId): array
    {
        DB::beginTransaction();

        try {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);

            // Read the business row through RLS too. If the id does not exist —
            // or RLS hides it — we must fail rather than emit an empty export
            // that looks like a legitimately empty tenant.
            $business = DB::table('businesses')->where('id', $businessId)->first();

            if ($business === null) {
                throw new RuntimeException("Tenant not found or not visible: {$businessId}");
            }

            $data = [];

            foreach (self::TABLES as $table) {
                $data[$table] = DB::table($table)->orderBy('id')->get()
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
