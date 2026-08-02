<?php
// app/Import/TenantImporter.php

namespace App\Import;

use App\Models\Customer;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Stock\MaterialUnit;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Ingests a shop's customers (with opening outstanding) and raw materials
 * (with current stock) into a single tenant, idempotently.
 *
 * Idempotency comes from a deterministic UUIDv5 natural key per row: re-running
 * the same file resolves to the same uuids, so the existing (business_id, uuid)
 * unique constraints turn a re-import into an in-place update rather than a
 * duplicate — no schema change, no upsert clause.
 *
 * Tenant context is owned here (not by the caller): the tenant binding
 * only takes effect inside a transaction, and the app-level BelongsToTenant scope
 * reads app('tenant.id') — so each import call opens one transaction, switches
 * both layers in, then commits (or, on dry-run, rolls back). This mirrors the
 * TenantAwareJob pattern for non-HTTP tenant work.
 */
class TenantImporter
{
    /** Fixed application namespace for derived natural keys. */
    private const NAMESPACE_UUID = '6f9619ff-8b86-d011-b42d-00c04fc964ff';

    public function importCustomers(string $businessId, iterable $rows, bool $dryRun): ImportReport
    {
        return $this->runInTenant($businessId, $dryRun, function (ImportReport $report) use ($businessId, $rows) {
            $i = 0;

            foreach ($rows as $row) {
                $i++;

                $name = $this->norm($row['name'] ?? '');
                if ($name === '') {
                    $report->addError($i, 'name is required');
                    continue;
                }

                $opening = trim((string) ($row['opening_balance'] ?? ''));
                if ($opening !== '' && ! is_numeric($opening)) {
                    $report->addError($i, 'opening_balance must be a number');
                    continue;
                }
                if ($opening !== '' && (float) $opening < 0) {
                    $report->addError($i, 'opening_balance must be >= 0');
                    continue;
                }

                $village = $this->norm($row['village'] ?? '');
                $phone = $this->norm($row['phone'] ?? '');

                $uuid = $this->keyUuid($businessId, 'customer', mb_strtolower($name), mb_strtolower($village));

                $attrs = [
                    'name' => $name,
                    'village' => $village !== '' ? $village : null,
                    'phone' => $phone !== '' ? $phone : null,
                    'opening_balance' => $opening === '' ? '0.00' : $opening,
                ];

                $existing = Customer::where('uuid', $uuid)->first();

                if ($existing !== null) {
                    $existing->update($attrs);
                    $report->updated++;
                } else {
                    Customer::create(['uuid' => $uuid] + $attrs);
                    $report->created++;
                }
            }
        });
    }

    public function importRawMaterials(string $businessId, iterable $rows, bool $dryRun, int $ownerUserId): ImportReport
    {
        return $this->runInTenant($businessId, $dryRun, function (ImportReport $report) use ($businessId, $rows, $ownerUserId) {
            $i = 0;

            foreach ($rows as $row) {
                $i++;

                $name = $this->norm($row['name'] ?? '');
                if ($name === '') {
                    $report->addError($i, 'name is required');
                    continue;
                }

                $unit = $this->norm($row['unit'] ?? '');
                $unit = $unit !== '' ? mb_strtolower($unit) : 'kg';
                if (! MaterialUnit::isValid($unit)) {
                    $report->addError($i, 'unit must be one of: ' . implode(', ', MaterialUnit::keys()));
                    continue;
                }

                $reorder = trim((string) ($row['reorder_level'] ?? ''));
                if ($reorder !== '' && (! is_numeric($reorder) || (float) $reorder < 0)) {
                    $report->addError($i, 'reorder_level must be a number >= 0');
                    continue;
                }

                $openingStock = trim((string) ($row['opening_stock'] ?? ''));
                if ($openingStock !== '' && (! is_numeric($openingStock) || (float) $openingStock < 0)) {
                    $report->addError($i, 'opening_stock must be a number >= 0');
                    continue;
                }

                $mUuid = $this->keyUuid($businessId, 'material', mb_strtolower($name));

                $mAttrs = [
                    'name' => $name,
                    'unit' => $unit,
                    'reorder_level' => $reorder === '' ? null : $reorder,
                ];

                $material = RawMaterial::where('uuid', $mUuid)->first();

                if ($material !== null) {
                    $material->update($mAttrs);
                    $report->updated++;
                } else {
                    $material = RawMaterial::create(['uuid' => $mUuid] + $mAttrs);
                    $report->created++;
                }

                $this->applyOpeningStock($businessId, $material, $openingStock, $ownerUserId);
            }
        });
    }

    /**
     * A single, deterministic opening-stock movement per material. Because it is
     * keyed on its own derived uuid, a re-import corrects the same 'in' entry in
     * place instead of stacking another one — opening stock stays a correctable
     * value, not an ever-growing pile of movements.
     */
    private function applyOpeningStock(string $businessId, RawMaterial $material, string $openingStock, int $ownerUserId): void
    {
        if ($openingStock === '' || (float) $openingStock <= 0) {
            return;
        }

        $sUuid = $this->keyUuid($businessId, 'opening-stock', mb_strtolower($material->name));

        $movement = StockMovement::where('uuid', $sUuid)->first();

        if ($movement !== null) {
            $movement->update(['qty' => $openingStock]);

            return;
        }

        $movement = new StockMovement([
            'uuid' => $sUuid,
            'raw_material_id' => $material->id,
            'movement_date' => now()->toDateString(),
            'kind' => 'in',
            'qty' => $openingStock,
            'note' => 'Opening stock (import)',
        ]);
        // created_by is not fillable — it is provenance, set directly.
        $movement->created_by = $ownerUserId;
        $movement->save();
    }

    /**
     * @param  callable(ImportReport): void  $work
     */
    private function runInTenant(string $businessId, bool $dryRun, callable $work): ImportReport
    {
        $report = new ImportReport();

        DB::beginTransaction();

        try {
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);

            $work($report);

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $report;
    }

    /** Collapse internal whitespace and trim; no case change (callers lowercase for keys). */
    private function norm(?string $s): string
    {
        return preg_replace('/\s+/', ' ', trim((string) $s));
    }

    private function keyUuid(string $businessId, string ...$parts): string
    {
        return (string) Uuid::uuid5(
            Uuid::fromString(self::NAMESPACE_UUID),
            $businessId . '|' . implode('|', $parts)
        );
    }
}
