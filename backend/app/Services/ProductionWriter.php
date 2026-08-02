<?php
// app/Services/ProductionWriter.php

namespace App\Services;

use App\Models\MaterialConsumption;
use App\Models\Product;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one home for the idempotent production write. Completing a batch does two
 * things atomically: it records what was consumed (a MaterialConsumption per
 * line) AND draws stock down through the SAME ledger GET /stock reads (an `out`
 * StockMovement per line, signed negative, tagged with the batch). So on-hand is
 * never a separate number that can drift from consumption — it is always
 * Σ movements, and each draw-down is traceable back to its batch.
 *
 * Extracted like LedgerWriter so the online and (future) offline paths share one
 * code path. Input is expected to be already validated against rulesForBatch().
 */
class ProductionWriter
{
    /** @return array<string, array<int, mixed>> */
    public static function rulesForBatch(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'product_id' => ['required', 'uuid'],
            'batch_date' => ['required', 'date'],
            'output_kg' => ['required', 'numeric', 'gt:0'],
            'consumptions' => ['required', 'array', 'min:1'],
            'consumptions.*.raw_material_id' => ['required', 'uuid'],
            'consumptions.*.qty' => ['required', 'numeric', 'gt:0'], // positive amount consumed
        ];
    }

    /** @return array{0: ProductionBatch, 1: bool} */
    public function createBatch(array $data): array
    {
        // Idempotent by (business_id, uuid): a replay returns the existing batch
        // with its consumptions, never a second draw-down.
        $existing = ProductionBatch::where('uuid', $data['uuid'])->first();
        if ($existing) {
            return [$existing->load('consumptions'), false];
        }

        // findOrFail under the tenant scope: a cross-tenant product is invisible → 404.
        $product = Product::findOrFail($data['product_id']);

        $batch = DB::transaction(function () use ($data, $product) {
            $batch = new ProductionBatch([
                'business_id' => app('tenant.id'),
                'uuid' => $data['uuid'],
                'product_id' => $product->id,
                'batch_date' => $data['batch_date'],
                'output_kg' => $data['output_kg'],
            ]);
            $batch->created_by = app('tenant.user_id');
            $batch->save();

            foreach ($data['consumptions'] as $line) {
                // findOrFail under the tenant scope: a cross-tenant material is invisible → 404.
                $material = RawMaterial::findOrFail($line['raw_material_id']);
                $qty = (string) $line['qty'];

                MaterialConsumption::create([
                    'business_id' => app('tenant.id'),
                    'production_batch_id' => $batch->id,
                    'raw_material_id' => $material->id,
                    'qty' => $qty, // positive amount consumed
                ]);

                // The stock draw-down: a signed-negative `out` movement tagged with
                // the batch, so on-hand drops through the same ledger and "why did
                // stock drop" is answerable. Over-consumption drives on-hand
                // negative — recorded, not blocked (PRD §8).
                $movement = new StockMovement([
                    'business_id' => app('tenant.id'),
                    'uuid' => (string) Str::uuid(), // server-minted: not a client row
                    'raw_material_id' => $material->id,
                    'movement_date' => $data['batch_date'],
                    'kind' => 'out',
                    'qty' => bcmul($qty, '-1', 3),
                    'note' => 'Production batch consumption',
                    'production_batch_id' => $batch->id,
                ]);
                $movement->created_by = app('tenant.user_id');
                $movement->save();
            }

            return $batch;
        });

        return [$batch->load('consumptions'), true];
    }
}
