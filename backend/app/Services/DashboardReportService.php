<?php
// app/Services/DashboardReportService.php

namespace App\Services;

use App\Models\Customer;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Reports\CustomerDue;
use App\Reports\DashboardReport;
use App\Reports\LowStockRow;
use App\Reports\OutstandingSummary;
use App\Reports\ProductPerf;
use App\Reports\ReportPeriod;
use App\Reports\TrendRow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        private readonly StockService $stock,
    ) {}

    public function forMonth(string $businessId, ReportPeriod $period): DashboardReport
    {
        $salesTrend = $this->salesTrend($businessId, $period->year);
        $prodTrend = $this->productionTrend($businessId, $period->year);
        $trend = array_map(
            fn (int $m) => new TrendRow($m, $salesTrend[$m - 1], $prodTrend[$m - 1]),
            range(1, 12),
        );

        $performance = $this->productPerformance($businessId, $period->year);
        $lowStock = $this->lowStock($businessId);

        $highestSelling = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => $p->qtySold)->first();
        $highestProfit = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => (float) $p->estProfitRupees)->first();

        return new DashboardReport(
            period: $period,
            salesTodayRupees: $this->salesToday($businessId),
            salesMonthRupees: $this->salesForMonth($businessId, $period->year, $period->month),
            outstanding: $this->customerOutstanding($businessId),
            productionMonthKg: $this->productionForMonth($businessId, $period->year, $period->month),
            lowStock: $lowStock,
            lowStockCount: count($lowStock),
            productPerformance: $performance,
            highestSellingName: $highestSelling?->name,
            highestProfitName: $highestProfit?->name,
            trend: $trend,
        );
    }

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

    public function salesToday(string $businessId): string
    {
        $sum = (string) Sale::query()
            ->where('business_id', $businessId)
            ->whereDate('sale_date', Carbon::now()->toDateString())
            ->selectRaw('coalesce(sum(total), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 2);
    }

    public function salesForMonth(string $businessId, int $year, int $month): string
    {
        $sum = (string) Sale::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from sale_date) = ?', [$year])
            ->whereRaw('extract(month from sale_date) = ?', [$month])
            ->selectRaw('coalesce(sum(total), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 2);
    }

    /** @return list<string> 12 decimal strings, index 0 = January. */
    public function salesTrend(string $businessId, int $year): array
    {
        $byMonth = Sale::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from sale_date) = ?', [$year])
            ->selectRaw('extract(month from sale_date)::int as m, coalesce(sum(total), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 2),
            range(1, 12),
        );
    }

    public function productionForMonth(string $businessId, int $year, int $month): string
    {
        $sum = (string) ProductionBatch::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from batch_date) = ?', [$year])
            ->whereRaw('extract(month from batch_date) = ?', [$month])
            ->selectRaw('coalesce(sum(output_kg), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 3);
    }

    /** @return list<string> 12 scale-3 kg strings, index 0 = January. */
    public function productionTrend(string $businessId, int $year): array
    {
        $byMonth = ProductionBatch::query()
            ->where('business_id', $businessId)
            ->whereRaw('extract(year from batch_date) = ?', [$year])
            ->selectRaw('extract(month from batch_date)::int as m, coalesce(sum(output_kg), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 3),
            range(1, 12),
        );
    }

    /**
     * Materials below their reorder level. Reuses StockService so the on-hand
     * and threshold logic stays in one place. Raw materials per tenant are few,
     * so a per-material check is fine (no N+1 concern at this cardinality).
     *
     * @return list<LowStockRow>
     */
    public function lowStock(string $businessId): array
    {
        return RawMaterial::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->get()
            ->filter(fn (RawMaterial $m) => $this->stock->belowReorder($m))
            ->map(fn (RawMaterial $m) => new LowStockRow(
                $m->name,
                $this->stock->onHandFor($m),
                bcadd((string) $m->reorder_level, '0', 3),
            ))
            ->values()
            ->all();
    }

    /**
     * Per product-pack sales for the year: qty, revenue, estimated cost
     * (qty × default_cost_price, treated as 0 when unpriced) and margin.
     * Ordered by revenue, highest first.
     *
     * @return list<ProductPerf>
     */
    public function productPerformance(string $businessId, int $year): array
    {
        $rows = DB::table('sale_lines as sl')
            ->join('sales as s', 's.id', '=', 'sl.sale_id')
            ->join('product_packs as pp', 'pp.id', '=', 'sl.product_pack_id')
            ->join('products as prod', 'prod.id', '=', 'pp.product_id')
            ->join('pack_sizes as ps', 'ps.id', '=', 'pp.pack_size_id')
            ->where('sl.business_id', $businessId)
            ->whereRaw('extract(year from s.sale_date) = ?', [$year])
            ->groupBy('pp.id', 'prod.name_en', 'prod.name_hi', 'ps.label', 'pp.default_cost_price')
            ->selectRaw("
                coalesce(prod.name_en, prod.name_hi) || ' ' || ps.label as name,
                sum(sl.qty)::int as qty,
                sum(sl.line_total)::text as sales,
                sum(sl.qty * coalesce(pp.default_cost_price, 0))::text as est_cost
            ")
            ->get();

        return $rows
            ->map(function ($r) {
                $sales = bcadd($r->sales, '0', 2);
                $cost = bcadd($r->est_cost, '0', 2);
                $profit = bcsub($sales, $cost, 2);
                $margin = bccomp($sales, '0.00', 2) === 0
                    ? '0.0'
                    : bcadd(bcmul(bcdiv($profit, $sales, 4), '100', 2), '0', 1);

                return new ProductPerf($r->name, (int) $r->qty, $sales, $cost, $profit, $margin);
            })
            ->sortByDesc(fn (ProductPerf $p) => (float) $p->salesRupees)
            ->values()
            ->all();
    }
}
