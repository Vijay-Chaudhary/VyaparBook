<?php
// app/Services/DashboardReportService.php

namespace App\Services;

use App\Models\Customer;
use App\Reports\CustomerDue;
use App\Reports\OutstandingSummary;

/**
 * Read-only aggregation behind the owner dashboard (Phase 0).
 *
 * Every method assumes it runs inside an already tenant-pinned transaction
 * (the controller's runInTenant in production, or the test harness's
 * inTenant() helper). RLS is FORCE'd on the app connection, so the tenant GUC
 * must be set by the caller. The ->where('business_id', ...) each method also
 * applies is the app-level layer of defense in depth on top of that — never
 * one layer alone — but it is not a substitute for the caller's tenant pin.
 *
 * All money is bcmath decimal strings, never floats, matching KhataService.
 */
class DashboardReportService
{
    public function __construct(
        private readonly KhataService $khata,
        private readonly StockService $stock,
    ) {}

    /**
     * Total and per-customer outstanding, reproducing KhataService's identity
     * (opening + Σ sales − Σ payments) as one query — no per-customer loop. The
     * total equals Σ KhataService::outstandingFor by construction; the service
     * test asserts exactly that.
     */
    public function customerOutstanding(string $businessId): OutstandingSummary
    {
        $rows = Customer::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->selectRaw('name, village, (
                opening_balance
                + coalesce((select sum(s.total) from sales s where s.customer_id = customers.id), 0)
                - coalesce((select sum(p.amount) from payments p where p.customer_id = customers.id), 0)
            )::text as outstanding')
            ->get();

        $customers = $rows
            ->map(fn ($r) => new CustomerDue($r->name, $r->village, bcadd($r->outstanding, '0', 2)))
            ->sortByDesc(fn (CustomerDue $c) => (float) $c->outstandingRupees)
            ->values()
            ->all();

        $total = array_reduce(
            $customers,
            fn (string $carry, CustomerDue $c) => bcadd($carry, $c->outstandingRupees, 2),
            '0.00',
        );

        return new OutstandingSummary($total, $customers);
    }
}
