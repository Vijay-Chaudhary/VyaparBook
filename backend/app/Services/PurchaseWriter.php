<?php
// app/Services/PurchaseWriter.php

namespace App\Services;

use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a raw-material Purchase and the costed stock-in it implies, atomically.
 * Mirrors ProductionWriter: business_id via BelongsToTenant, created_by stamped
 * from the tenant context, a server-minted StockMovement so on-hand stays correct
 * through the one ledger. Assumes it runs inside a tenant-pinned transaction.
 *
 * Money is bcmath decimal strings, never floats. `total` is computed here
 * (qty × unit_cost), never trusted from the caller.
 */
class PurchaseWriter
{
    /**
     * @param  array{uuid: string, supplier_id: string, raw_material_id: string, purchase_date: string, qty: string, unit_cost: string, note: ?string}  $data
     * @return array{0: Purchase, 1: bool}  [purchase, wasCreated]
     */
    public function record(array $data): array
    {
        // Idempotent by (business_id, uuid): a replay returns the existing row,
        // never a second stock-in.
        $existing = Purchase::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return [$existing, false];
        }

        // findOrFail under RLS: a cross-tenant supplier/material is invisible → 404.
        $supplier = Supplier::findOrFail($data['supplier_id']);
        $material = RawMaterial::findOrFail($data['raw_material_id']);

        $qty = bcadd((string) $data['qty'], '0', 3);
        $unitCost = bcadd((string) $data['unit_cost'], '0', 2);
        $total = bcmul($qty, $unitCost, 2);

        $purchase = DB::transaction(function () use ($data, $supplier, $material, $qty, $unitCost, $total) {
            $purchase = new Purchase([
                'business_id' => app('tenant.id'),
                'uuid' => $data['uuid'],
                'supplier_id' => $supplier->id,
                'raw_material_id' => $material->id,
                'purchase_date' => $data['purchase_date'],
                'qty' => $qty,
                'unit_cost' => $unitCost,
                'total' => $total,
                'note' => $data['note'] ?? null,
            ]);
            $purchase->created_by = app('tenant.user_id');
            $purchase->save();

            // The costed stock-in: a positive `in` movement tagged with the
            // purchase, so on-hand rises through the same ledger the app reads.
            $movement = new StockMovement([
                'business_id' => app('tenant.id'),
                'uuid' => (string) Str::uuid(), // server-minted: not a client row
                'raw_material_id' => $material->id,
                'movement_date' => $data['purchase_date'],
                'kind' => 'in',
                'qty' => $qty,
                'note' => 'Purchase',
                'purchase_id' => $purchase->id,
            ]);
            $movement->created_by = app('tenant.user_id');
            $movement->save();

            return $purchase;
        });

        return [$purchase, true];
    }

    /**
     * Archive a purchase and remove the stock-in it created, so on-hand does not
     * overcount. Runs in one transaction.
     */
    public function remove(Purchase $purchase): void
    {
        DB::transaction(function () use ($purchase) {
            StockMovement::where('purchase_id', $purchase->id)->delete();
            $purchase->archived_at = now();
            $purchase->save();
        });
    }
}
