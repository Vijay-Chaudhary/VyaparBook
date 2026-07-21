<?php
// app/Services/DashboardReportService.php

namespace App\Services;

use App\Models\Customer;
use App\Reports\CustomerDue;
use App\Reports\OutstandingSummary;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Read-only aggregation behind the owner dashboard (Phase 0). Every method is
 * scoped explicitly by $businessId, not an ambient request tenant, so — same
 * pattern as TenantAwareJob — it (re)establishes both the RLS GUC and the
 * app-level tenant binding itself rather than assuming a caller already has.
 * The ->where('business_id', ...) below still runs regardless: defense in
 * depth, never one layer alone.
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
        return DB::transaction(function () use ($businessId) {
            // set_config(..., true) is SET LOCAL, transaction-scoped — hence the
            // wrapping transaction above, not a bare call.
            TenantContext::switchTo($businessId);
            app()->bind('tenant.id', fn () => $businessId);

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
        });
    }
}
