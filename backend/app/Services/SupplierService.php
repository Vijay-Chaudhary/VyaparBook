<?php
// app/Services/SupplierService.php

namespace App\Services;

use App\Models\Supplier;
use App\Reports\SupplierDue;
use App\Reports\SupplierOutstandingSummary;
use Illuminate\Support\Collection;

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
            ->selectRaw('id, name, village, CAST((' . $this->outstandingExpr() . ') AS CHAR) as outstanding')
            ->get();

        $suppliers = $rows
            ->map(fn ($r) => new SupplierDue($r->id, $r->name, $r->village, bcadd($r->outstanding, '0', 2)))
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

    /**
     * A time-ordered payables statement: every purchase and payment for the
     * supplier as one stream with a running balance. Purchases raise what we owe
     * (credit: +), payments lower it (debit: −); the last running value equals
     * outstandingFor(). Mirrors KhataService::ledgerFor on the buy side.
     *
     * @return Collection<int, array{kind: string, ref: \Illuminate\Database\Eloquent\Model, date: \Illuminate\Support\Carbon, delta: string, running_balance: string}>
     */
    public function ledgerFor(Supplier $supplier): Collection
    {
        $entries = $supplier->purchases()->whereNull('archived_at')->get()
            ->map(fn ($p) => [
                'kind' => 'purchase',
                'ref' => $p,
                'date' => $p->purchase_date,
                'delta' => (string) $p->total,                  // we owe more: +
            ])
            ->concat($supplier->supplierPayments()->whereNull('archived_at')->get()->map(fn ($sp) => [
                'kind' => 'payment',
                'ref' => $sp,
                'date' => $sp->payment_date,
                'delta' => bcmul((string) $sp->amount, '-1', 2), // we owe less: −
            ]))
            ->sortBy([
                ['date', 'asc'],
                fn ($a, $b) => $a['ref']->created_at <=> $b['ref']->created_at,
            ])
            ->values();

        $running = bcadd((string) $supplier->opening_balance, '0', 2);

        return $entries->map(function ($e) use (&$running) {
            $running = bcadd($running, $e['delta'], 2);
            $e['running_balance'] = $running;

            return $e;
        });
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
