<?php
// app/Services/CogsService.php

namespace App\Services;

use App\Reports\PackCost;
use Illuminate\Support\Facades\DB;

/**
 * Actual cost of goods sold, from production (Phase 2b).
 *
 * The chain, each link already recorded:
 *
 *   purchases             → material ₹/kg       (Phase 2a: Σ total ÷ Σ qty)
 *   material_consumptions → batch material cost (Σ qty × material ₹/kg)
 *   production_batches    → product ₹/kg        (Σ batch cost ÷ Σ output_kg)
 *   pack_sizes.weight_kg  → pack cost           (product ₹/kg × weight_kg)
 *
 * Lifetime weighted average per product, never a snapshot on the sale line:
 * cost is always recomputable from the ledger (PRD §9), the same property
 * outstanding and on-hand have. The accepted consequence is that buying
 * materials later moves the average, so a past month's gross profit can change.
 *
 * Cost means MATERIALS ONLY. Labour and electricity are operating expenses and
 * already sit below the gross line — counting them here would double them.
 *
 * Three grouped queries, no per-product loop; every division stays in bcmath at
 * the scales Phase 2a established, so a headline figure and a single-product
 * answer cannot drift apart. Assumes a tenant-pinned transaction.
 */
class CogsService
{
    /**
     * Per-request memo, keyed by businessId. forMonth() asks for the same
     * tenant's pack costs three times (gross-profit trend, estimated-share,
     * product performance); without this each call re-ran the whole chain.
     * The service is request-scoped and the key is the tenant, so nothing
     * bleeds across tenants or across requests.
     *
     * @var array<string, array<string, string>>
     */
    private array $materialCostMemo = [];

    /** @var array<string, array<string, string>> */
    private array $productCostMemo = [];

    /** @var array<string, array<string, PackCost>> */
    private array $packCostMemo = [];

    /**
     * Weighted-average ₹/kg per raw material, from non-archived purchases.
     * Mirrors PurchaseService::costPerKgFor set-wide.
     *
     * @return array<string, string> materialId => ₹/kg, scale 2
     */
    public function materialCostPerKg(string $businessId): array
    {
        return $this->materialCostMemo[$businessId] ??= $this->computeMaterialCostPerKg($businessId);
    }

    /** @return array<string, string> */
    private function computeMaterialCostPerKg(string $businessId): array
    {
        $rows = DB::table('purchases')
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->groupBy('raw_material_id')
            ->selectRaw('raw_material_id, sum(total)::text as tot, sum(qty)::text as q')
            ->get();

        $costs = [];
        foreach ($rows as $r) {
            $costs[$r->raw_material_id] = $this->divide($r->tot, $r->q, 3);
        }

        return $costs;
    }

    /**
     * Actual ₹/kg per product: Σ (what its batches consumed, priced) ÷ Σ output.
     *
     * A product is ABSENT from the map when it has no batches, or when its
     * batches produced nothing — there is no actual cost to report, and the
     * caller falls back to the owner's estimate rather than dividing by zero.
     *
     * @return array<string, string> productId => ₹/kg, scale 2
     */
    public function productCostPerKg(string $businessId): array
    {
        return $this->productCostMemo[$businessId] ??= $this->computeProductCostPerKg($businessId);
    }

    /** @return array<string, string> */
    private function computeProductCostPerKg(string $businessId): array
    {
        $materialCost = $this->materialCostPerKg($businessId);

        // What each product's batches consumed, per material.
        $consumed = DB::table('material_consumptions as mc')
            ->join('production_batches as pb', 'pb.id', '=', 'mc.production_batch_id')
            ->where('mc.business_id', $businessId)
            ->groupBy('pb.product_id', 'mc.raw_material_id')
            ->selectRaw('pb.product_id, mc.raw_material_id, sum(mc.qty)::text as qty')
            ->get();

        $costByProduct = [];
        foreach ($consumed as $r) {
            $rate = $materialCost[$r->raw_material_id] ?? '0.00';
            $costByProduct[$r->product_id] = bcadd(
                $costByProduct[$r->product_id] ?? '0.00',
                bcmul($r->qty, $rate, 2),
                2,
            );
        }

        // What they produced.
        $output = DB::table('production_batches')
            ->where('business_id', $businessId)
            ->groupBy('product_id')
            ->selectRaw('product_id, sum(output_kg)::text as kg')
            ->pluck('kg', 'product_id');

        $perKg = [];
        foreach ($costByProduct as $productId => $cost) {
            $kg = (string) ($output[$productId] ?? '0');
            $rate = $this->divide($cost, $kg, 3);
            // A zero-output product yields '0.00' from divide(); that is "not
            // costable", not "free", so it stays out of the map entirely.
            if (bccomp($rate, '0.00', 2) !== 0) {
                $perKg[$productId] = $rate;
            }
        }

        return $perKg;
    }

    /**
     * The cost of ONE pack of each product pack, and where that cost came from.
     *
     * Production wherever the product has batches; otherwise the owner's
     * default_cost_price, exactly as before Phase 2b — so nothing regresses for
     * bought-in goods, and the report can say how much is still estimated.
     *
     * @return array<string, PackCost> packId => cost
     */
    public function packCosts(string $businessId): array
    {
        return $this->packCostMemo[$businessId] ??= $this->computePackCosts($businessId);
    }

    /** @return array<string, PackCost> */
    private function computePackCosts(string $businessId): array
    {
        $productCost = $this->productCostPerKg($businessId);

        $packs = DB::table('product_packs as pp')
            ->join('pack_sizes as ps', 'ps.id', '=', 'pp.pack_size_id')
            ->where('pp.business_id', $businessId)
            ->selectRaw('pp.id, pp.product_id, pp.default_cost_price::text as est, ps.weight_kg::text as kg')
            ->get();

        $costs = [];
        foreach ($packs as $p) {
            $costs[$p->id] = isset($productCost[$p->product_id])
                ? new PackCost(bcmul($p->kg, $productCost[$p->product_id], 2), true)
                : new PackCost(bcadd((string) ($p->est ?? '0'), '0', 2), false);
        }

        return $costs;
    }

    /**
     * Σ numerator ÷ Σ denominator at scale 2, or '0.00' when the denominator is
     * zero. Division by zero here means "nothing was ever bought/produced", not
     * an error — there is simply no rate to report. One place, so every caller
     * rounds identically (the PurchaseService::weightedAvg discipline).
     */
    private function divide(string $numerator, string $denominator, int $denominatorScale): string
    {
        $denominator = bcadd($denominator, '0', $denominatorScale);
        if (bccomp($denominator, '0', $denominatorScale) === 0) {
            return '0.00';
        }

        return bcdiv($numerator, $denominator, 2);
    }
}
