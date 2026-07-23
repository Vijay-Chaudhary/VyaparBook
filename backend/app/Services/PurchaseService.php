<?php
// app/Services/PurchaseService.php

namespace App\Services;

use App\Models\Purchase;
use App\Models\RawMaterial;
use App\Reports\StockValuationRow;

/**
 * Raw-material valuation from costed purchases (Phase 2a). Weighted-average,
 * purchase-lifetime: Cost/Kg = Σ purchase.total ÷ Σ purchase.qty per material;
 * Stock Value = Σ (on-hand × Cost/Kg). Archived purchases are excluded. bcmath
 * throughout; assumes a tenant-pinned transaction.
 */
class PurchaseService
{
    public function __construct(private readonly StockService $stock) {}

    /** Weighted-average cost per kg for a material; '0.00' when never purchased. */
    public function costPerKgFor(RawMaterial $material): string
    {
        $row = Purchase::query()
            ->where('business_id', $material->business_id)
            ->where('raw_material_id', $material->id)
            ->whereNull('archived_at')
            ->selectRaw('coalesce(sum(total), 0)::text as tot, coalesce(sum(qty), 0)::text as q')
            ->first();

        $qty = bcadd((string) ($row->q ?? '0'), '0', 3);
        if (bccomp($qty, '0.000', 3) === 0) {
            return '0.00';
        }

        return bcdiv((string) $row->tot, $qty, 2);
    }

    /**
     * Per-material valuation rows: name, on-hand, Cost/Kg, value (= on-hand ×
     * Cost/Kg). Materials never purchased carry Cost/Kg '0.00' and value '0.00'.
     *
     * @return list<StockValuationRow>
     */
    public function stockValuationRows(string $businessId): array
    {
        return RawMaterial::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get()
            ->map(function (RawMaterial $m) {
                $onHand = $this->stock->onHandFor($m);          // scale 3
                $costPerKg = $this->costPerKgFor($m);            // scale 2
                $value = bcmul($onHand, $costPerKg, 2);          // ₹

                return new StockValuationRow($m->name, $onHand, $costPerKg, $value);
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
