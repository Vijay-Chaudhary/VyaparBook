<?php
// app/Services/PurchaseService.php

namespace App\Services;

use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Reports\StockValuationRow;
use Illuminate\Support\Facades\DB;

/**
 * Raw-material valuation from costed purchases (Phase 2a). Weighted-average,
 * purchase-lifetime: Cost/Kg = Σ purchase.total ÷ Σ purchase.qty per material;
 * Stock Value = Σ (on-hand × Cost/Kg). Archived purchases are excluded. bcmath
 * throughout; assumes a tenant-pinned transaction.
 */
class PurchaseService
{
    /** Weighted-average cost per kg for a material; '0.00' when never purchased. */
    public function costPerKgFor(RawMaterial $material): string
    {
        $row = Purchase::query()
            ->where('business_id', $material->business_id)
            ->where('raw_material_id', $material->id)
            ->whereNull('archived_at')
            ->selectRaw('CAST(coalesce(sum(total), 0) AS CHAR) as tot, CAST(coalesce(sum(qty), 0) AS CHAR) as q')
            ->first();

        return $this->weightedAvg((string) ($row->tot ?? '0'), (string) ($row->q ?? '0'));
    }

    /**
     * Σ total ÷ Σ qty at scale 2, or '0.00' when nothing was ever purchased —
     * the one place the weighted average is defined, so the per-material and
     * set-based paths cannot drift apart. Division by zero is the "never
     * purchased" case, not an error: there is simply no cost to report.
     */
    private function weightedAvg(string $total, string $qty): string
    {
        $qty = bcadd($qty, '0', 3);
        if (bccomp($qty, '0.000', 3) === 0) {
            return '0.00';
        }

        return bcdiv($total, $qty, 2);
    }

    /**
     * Per-material valuation rows: name, on-hand, Cost/Kg, value (= on-hand ×
     * Cost/Kg). Materials never purchased carry Cost/Kg '0.00' and value '0.00'.
     *
     * One query for every material, not StockService::onHandFor per row: the
     * dashboard renders this for the whole catalogue, and a per-material loop
     * would be 2 queries × N materials. The database does the summing; the division
     * and the multiply stay in bcmath at the same scales costPerKgFor uses, so
     * a row here and a single-material call can never disagree.
     *
     * @return list<StockValuationRow>
     */
    public function stockValuationRows(string $businessId): array
    {
        $rows = DB::table('raw_materials as rm')
            ->where('rm.business_id', $businessId)
            ->whereNull('rm.archived_at')
            ->selectRaw(<<<'SQL'
                rm.name,
                CAST(coalesce((select sum(sm.qty) from stock_movements sm
                          where sm.raw_material_id = rm.id
                            and sm.business_id = rm.business_id), 0) AS CHAR) as on_hand,
                CAST(coalesce((select sum(p.total) from purchases p
                          where p.raw_material_id = rm.id
                            and p.business_id = rm.business_id
                            and p.archived_at is null), 0) AS CHAR) as purchased_total,
                CAST(coalesce((select sum(p.qty) from purchases p
                          where p.raw_material_id = rm.id
                            and p.business_id = rm.business_id
                            and p.archived_at is null), 0) AS CHAR) as purchased_qty
                SQL)
            ->orderBy('rm.name')
            ->get();

        return $rows
            ->map(function ($r) {
                $onHand = bcadd($r->on_hand, '0', 3);
                $costPerKg = $this->weightedAvg($r->purchased_total, $r->purchased_qty);

                return new StockValuationRow($r->name, $onHand, $costPerKg, bcmul($onHand, $costPerKg, 2));
            })
            ->values()
            ->all();
    }

    /** Total stock value across all materials. */
    public function stockValue(string $businessId): string
    {
        return array_reduce(
            $this->stockValuationRows($businessId),
            fn (string $carry, StockValuationRow $r) => bcadd($carry, $r->valueRupees, 2),
            '0.00',
        );
    }
}
