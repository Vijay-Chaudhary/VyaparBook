<?php
// app/Services/FinishedGoodsService.php

namespace App\Services;

use App\Models\Product;
use App\Reports\FinishedGoodsRow;
use Illuminate\Support\Facades\DB;

/**
 * How much of each product is actually on hand (PRD §18 Phase 3).
 *
 * Read-only and tenant-pinned, like the other report services: it assumes an
 * already-pinned transaction (the tenant scope bound), and the explicit
 * ->where('business_id', ...) is the app-level layer on top — never one alone.
 *
 * Stock here is WEIGHT, derived from what is already recorded: production adds
 * output_kg, a sale removes qty × the pack's weight_kg. Nothing is stored, so
 * on-hand can never drift from the events that produced it (PRD §9).
 *
 * Returns and voids are negative-qty sale lines, so Σ qty self-nets and no row
 * is ever excluded or mutated — the same discipline as payments and cash flow.
 */
class FinishedGoodsService
{
    /**
     * On-hand per product, since inception, biggest holding first.
     *
     * Products that were never produced and never sold are omitted: a row of
     * zeroes is noise on a screen meant to be scanned.
     *
     * @return list<FinishedGoodsRow>
     */
    public function onHand(string $businessId): array
    {
        $produced = DB::table('production_batches')
            ->where('business_id', $businessId)
            ->groupBy('product_id')
            ->selectRaw('product_id, CAST(coalesce(sum(output_kg), 0) AS CHAR) as kg')
            ->pluck('kg', 'product_id');

        // qty is an integer count of packs; weight_kg turns it into kg. The
        // join is the only place packs become weight.
        $sold = DB::table('sale_lines')
            ->join('product_packs', 'product_packs.id', '=', 'sale_lines.product_pack_id')
            ->join('pack_sizes', 'pack_sizes.id', '=', 'product_packs.pack_size_id')
            ->where('sale_lines.business_id', $businessId)
            ->groupBy('product_packs.product_id')
            ->selectRaw('product_packs.product_id as product_id, CAST(coalesce(sum(sale_lines.qty * pack_sizes.weight_kg), 0) AS CHAR) as kg')
            ->pluck('kg', 'product_id');

        $products = Product::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->get();

        $rows = [];

        foreach ($products as $product) {
            $producedKg = bcadd((string) ($produced[$product->id] ?? '0'), '0', 3);
            $soldKg = bcadd((string) ($sold[$product->id] ?? '0'), '0', 3);

            if (bccomp($producedKg, '0', 3) === 0 && bccomp($soldKg, '0', 3) === 0) {
                continue;
            }

            $rows[] = new FinishedGoodsRow(
                productId: $product->id,
                name: $product->name_en ?: $product->name_hi,
                producedKg: $producedKg,
                soldKg: $soldKg,
                // May go negative: more sold than recorded as produced means
                // unrecorded production or a mis-keyed batch, and clamping it
                // would hide exactly the error this ledger exists to reveal.
                onHandKg: bcsub($producedKg, $soldKg, 3),
            );
        }

        usort($rows, fn (FinishedGoodsRow $a, FinishedGoodsRow $b) => bccomp($b->onHandKg, $a->onHandKg, 3));

        return $rows;
    }
}
