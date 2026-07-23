<?php
// app/Services/SupplierService.php

namespace App\Services;

use App\Models\Supplier;
use App\Reports\SupplierDue;
use App\Reports\SupplierOutstandingSummary;

/**
 * Supplier payables — the mirror of the customer khata, on the buy side.
 * Outstanding = opening_balance + Σ purchases − Σ supplier_payments (archived
 * rows excluded). Set-based, explicit business_id scope, bcmath decimal strings.
 * Assumes a tenant-pinned transaction (RLS + app scope, defense in depth).
 */
class SupplierService
{
    public function outstandingFor(Supplier $supplier): string
    {
        $row = Supplier::query()
            ->where('id', $supplier->id)
            ->selectRaw($this->outstandingExpr() . ' as outstanding')
            ->first();

        return bcadd((string) ($row?->outstanding ?? '0'), '0', 2);
    }

    /** Total and per-supplier outstanding, highest first. */
    public function outstandingSummary(string $businessId): SupplierOutstandingSummary
    {
        $rows = Supplier::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->selectRaw('name, village, (' . $this->outstandingExpr() . ')::text as outstanding')
            ->get();

        $suppliers = $rows
            ->map(fn ($r) => new SupplierDue($r->name, $r->village, bcadd($r->outstanding, '0', 2)))
            ->sortByDesc(fn (SupplierDue $s) => (float) $s->outstandingRupees)
            ->values()
            ->all();

        $total = array_reduce(
            $suppliers,
            fn (string $carry, SupplierDue $s) => bcadd($carry, $s->outstandingRupees, 2),
            '0.00',
        );

        return new SupplierOutstandingSummary($total, $suppliers);
    }

    /** opening + Σ non-archived purchases − Σ non-archived payments. */
    private function outstandingExpr(): string
    {
        return <<<'SQL'
            opening_balance
            + coalesce((select sum(p.total) from purchases p
                        where p.supplier_id = suppliers.id and p.archived_at is null), 0)
            - coalesce((select sum(sp.amount) from supplier_payments sp
                        where sp.supplier_id = suppliers.id and sp.archived_at is null), 0)
            SQL;
    }
}
