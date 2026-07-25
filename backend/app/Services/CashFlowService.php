<?php
// app/Services/CashFlowService.php

namespace App\Services;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\SupplierPayment;
use Illuminate\Support\Collection;

/**
 * Read-only cash-flow aggregation behind the owner dashboard (Phase 3).
 *
 * Like DashboardReportService, every method assumes it runs inside an
 * already-tenant-pinned transaction (RLS FORCE'd on the app connection). The
 * explicit ->where('business_id', ...) is the app-level layer of defense in
 * depth on top of that — never one layer alone.
 *
 * "Cash" here is money that actually changed hands: customer collections in,
 * supplier payments + operating expenses out. Sales and purchases are accrual
 * and deliberately excluded. All money is bcmath scale-2 decimal strings.
 *
 * The three trend queries mirror the shape of DashboardReportService's own
 * sales/production/expense trends by design — one self-contained, independently
 * testable service owns the whole cash picture, rather than reaching across.
 */
class CashFlowService
{
    /**
     * Cash in per month: Σ payments.amount grouped by month. A reversal is a row
     * with a negated amount, so the sum self-nets; payments have no soft-delete.
     *
     * @return list<string> 12 scale-2 strings, index 0 = January.
     */
    public function cashInTrend(string $businessId, int $year): array
    {
        $byMonth = Payment::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from payment_date) = ?', [$year])
            ->selectRaw('extract(month from payment_date)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return $this->twelve($byMonth);
    }

    /** @return list<string> 12 scale-2 strings; non-archived supplier payments. */
    public function supplierOutTrend(string $businessId, int $year): array
    {
        $byMonth = SupplierPayment::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from payment_date) = ?', [$year])
            ->selectRaw('extract(month from payment_date)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return $this->twelve($byMonth);
    }

    /** @return list<string> 12 scale-2 strings; non-archived operating expenses. */
    public function expenseOutTrend(string $businessId, int $year): array
    {
        $byMonth = Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->selectRaw('extract(month from spent_on)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return $this->twelve($byMonth);
    }

    /**
     * Cumulative net cash of every event STRICTLY BEFORE Jan 1 of $year:
     * Σ payments − Σ supplier_payments − Σ expenses (archived excluded on the
     * out side, matching the trends). Seeds the running position so the year
     * picker stays continuous with prior history; 0.00 for a tenant's first year.
     */
    public function openingPosition(string $businessId, int $year): string
    {
        $start = sprintf('%04d-01-01', $year);

        $in = (string) Payment::query()
            ->where('business_id', $businessId)
            ->whereRaw('payment_date < ?', [$start])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')->value('agg');

        $supplierOut = (string) SupplierPayment::query()
            ->where('business_id', $businessId)->whereNull('archived_at')
            ->whereRaw('payment_date < ?', [$start])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')->value('agg');

        $expenseOut = (string) Expense::query()
            ->where('business_id', $businessId)->whereNull('archived_at')
            ->whereRaw('spent_on < ?', [$start])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')->value('agg');

        return bcsub(bcsub(bcadd($in, '0', 2), $supplierOut, 2), $expenseOut, 2);
    }

    /**
     * Normalise a month=>agg map into 12 scale-2 strings, index 0 = January.
     *
     * @param  Collection<int, string>  $byMonth
     * @return list<string>
     */
    private function twelve(Collection $byMonth): array
    {
        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 2),
            range(1, 12),
        );
    }
}
