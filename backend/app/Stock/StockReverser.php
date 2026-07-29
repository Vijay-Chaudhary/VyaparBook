<?php
// app/Stock/StockReverser.php

namespace App\Stock;

use App\Ledger\ReversalNotAllowed;
use App\Models\MaterialConsumption;
use App\Models\ProductionBatch;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corrections for stock and production.
 *
 * The same discipline as LedgerReverser, for the same reason: on-hand is
 * Σ movements and finished goods is Σ output_kg, so nothing may be deleted or
 * edited — a correction is a new row with the amounts negated. Every figure
 * downstream (on-hand, finished goods, and the COGS ₹/kg derived from batch
 * cost ÷ output) then self-nets without knowing corrections exist.
 *
 * Separate from LedgerReverser rather than merged: that one is about the khata,
 * where a correction changes what a customer owes. Nothing here touches money
 * owed, and the two share only a shape.
 */
class StockReverser
{
    /**
     * Reverse a hand-recorded stock movement.
     *
     * `kind` is FLIPPED, not copied. The column labels intent and the schema's
     * invariant is that an `out` can never raise stock, so a reversal of an
     * `in` has to be an `out` — copying the kind would store a row whose label
     * contradicts its own sign.
     *
     * @throws ReversalNotAllowed
     */
    public function reverseMovement(StockMovement $original): StockMovement
    {
        if ($original->reverses_id) {
            throw ReversalNotAllowed::isReversal(__('stock.cannot_reverse_reversal'));
        }

        if (StockMovement::where('reverses_id', $original->id)->exists()) {
            throw ReversalNotAllowed::alreadyReversed(__('stock.already_reversed'));
        }

        // A movement the production or purchase writer created is not a
        // free-standing fact: reversing it alone would leave the batch's
        // consumption record, or the purchase, disagreeing with stock. Reverse
        // the thing that caused it instead.
        if ($original->production_batch_id) {
            throw ReversalNotAllowed::isReversal(__('stock.reverse_the_batch'));
        }

        if ($original->purchase_id) {
            throw ReversalNotAllowed::isReversal(__('stock.reverse_the_purchase'));
        }

        return $this->mirrorMovement($original, null);
    }

    /**
     * Reverse a whole production batch: the output AND the materials it drew.
     *
     * A batch did two things atomically, so undoing it must undo both — a
     * reversal that only negated the output would leave the raw materials
     * consumed by a batch that no longer counts as having happened.
     *
     * @throws ReversalNotAllowed
     */
    public function reverseBatch(ProductionBatch $original): ProductionBatch
    {
        if ($original->reverses_id) {
            throw ReversalNotAllowed::isReversal(__('stock.cannot_reverse_reversal'));
        }

        if (ProductionBatch::where('reverses_id', $original->id)->exists()) {
            throw ReversalNotAllowed::alreadyReversed(__('stock.batch_already_reversed'));
        }

        return DB::transaction(function () use ($original) {
            $reversal = new ProductionBatch([
                'business_id' => app('tenant.id'),
                'uuid' => (string) Str::uuid(), // server-minted: no client row
                'product_id' => $original->product_id,
                // Dated today, not backdated to the batch: the correction is an
                // event of its own, and rewriting a past month's production
                // chart is what this whole approach exists to avoid.
                'batch_date' => now()->toDateString(),
                'output_kg' => bcmul((string) $original->output_kg, '-1', 3),
            ]);
            $reversal->created_by = app('tenant.user_id');
            $reversal->reverses_id = $original->id;
            $reversal->save();

            foreach ($original->consumptions as $consumption) {
                // Negated consumption, so the COGS numerator (Σ qty × ₹/kg)
                // drops by exactly what this batch contributed, in step with
                // the denominator above.
                MaterialConsumption::create([
                    'business_id' => app('tenant.id'),
                    'production_batch_id' => $reversal->id,
                    'raw_material_id' => $consumption->raw_material_id,
                    'qty' => bcmul((string) $consumption->qty, '-1', 3),
                ]);
            }

            // And put the raw materials back, through the same ledger the
            // draw-down used, each pointing at the movement it cancels so that
            // movement can never also be reversed on its own.
            foreach ($original->movements as $movement) {
                $this->mirrorMovement($movement, $reversal->id);
            }

            return $reversal->load('consumptions');
        });
    }

    /**
     * The mirror image of one movement: same material and note lineage, negated
     * quantity, flipped kind.
     */
    private function mirrorMovement(StockMovement $original, ?string $batchId): StockMovement
    {
        $mirror = new StockMovement([
            'business_id' => app('tenant.id'),
            'uuid' => (string) Str::uuid(),
            'raw_material_id' => $original->raw_material_id,
            'movement_date' => now()->toDateString(),
            'kind' => self::flip($original->kind),
            'qty' => bcmul((string) $original->qty, '-1', 3),
            'note' => __('stock.correction_note'),
            'production_batch_id' => $batchId,
        ]);
        $mirror->created_by = app('tenant.user_id');
        $mirror->reverses_id = $original->id;
        $mirror->save();

        return $mirror;
    }

    /** in ↔ out; an adjustment reverses to an adjustment. */
    private static function flip(string $kind): string
    {
        return match ($kind) {
            'in' => 'out',
            'out' => 'in',
            default => 'adjust',
        };
    }
}
