<?php
// app/Services/DashboardReportService.php

namespace App\Services;

use App\Expenses\ExpenseCategory;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ProductionBatch;
use App\Models\RawMaterial;
use App\Models\Sale;
use App\Reports\CashFlowRow;
use App\Reports\CustomerDue;
use App\Reports\DashboardReport;
use App\Reports\ExpenseCategoryTotal;
use App\Reports\LowStockRow;
use App\Reports\OutstandingSummary;
use App\Reports\PackCost;
use App\Reports\ProductPerf;
use App\Reports\ReportPeriod;
use App\Reports\StockValuationRow;
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
        private readonly PurchaseService $purchases,
        private readonly SupplierService $suppliers,
        private readonly CogsService $cogs,
        private readonly CashFlowService $cash,
    ) {}

    public function forMonth(string $businessId, ReportPeriod $period): DashboardReport
    {
        $salesTrend = $this->salesTrend($businessId, $period->year);
        $prodTrend = $this->productionTrend($businessId, $period->year);
        $grossTrend = $this->grossProfitTrend($businessId, $period->year);
        $expensesTrend = $this->expensesTrend($businessId, $period->year);

        $trend = array_map(
            fn (int $m) => new TrendRow(
                $m,
                $salesTrend[$m - 1],
                $prodTrend[$m - 1],
                $grossTrend[$m - 1],
                $expensesTrend[$m - 1],
                bcsub($grossTrend[$m - 1], $expensesTrend[$m - 1], 2), // net profit (may be negative)
            ),
            range(1, 12),
        );

        $performance = $this->productPerformance($businessId, $period->year);
        $lowStock = $this->lowStock($businessId);

        // Valuation is a point-in-time figure, not a monthly one: it values the
        // stock on hand right now, so it does not move with the period picker.
        $stockValuation = $this->purchases->stockValuationRows($businessId);
        $stockValue = array_reduce(
            $stockValuation,
            fn (string $carry, StockValuationRow $r) => bcadd($carry, $r->valueRupees, 2),
            '0.00',
        );

        $highestSelling = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => $p->qtySold)->first();
        $highestProfit = collect($performance)
            ->sortByDesc(fn (ProductPerf $p) => (float) $p->profitRupees)->first();

        // Selected-month P&L figures. Gross and expenses come from the trends we
        // already fetched (index month-1); net = gross − expenses.
        $salesMonth = $this->salesForMonth($businessId, $period->year, $period->month);
        $grossMonth = $grossTrend[$period->month - 1];
        $expensesMonth = $expensesTrend[$period->month - 1];
        $netProfitMonth = bcsub($grossMonth, $expensesMonth, 2);

        $netMargin = bccomp($salesMonth, '0.00', 2) === 0
            ? '0.0'
            : bcadd(bcmul(bcdiv($netProfitMonth, $salesMonth, 4), '100', 2), '0', 1);

        // Phase 3: cash flow. Walk Jan..Dec once, carrying a running position
        // seeded from every event before this year, so the row position and the
        // selected-month headline come from the SAME walk and cannot drift.
        $cashIn = $this->cash->cashInTrend($businessId, $period->year);
        $supplierOut = $this->cash->supplierOutTrend($businessId, $period->year);
        $expenseOut = $this->cash->expenseOutTrend($businessId, $period->year);

        $position = $this->cash->openingPosition($businessId, $period->year);
        $cashTrend = [];
        foreach (range(1, 12) as $m) {
            $out = bcadd($supplierOut[$m - 1], $expenseOut[$m - 1], 2);
            $net = bcsub($cashIn[$m - 1], $out, 2);
            $position = bcadd($position, $net, 2);
            $cashTrend[] = new CashFlowRow($m, $cashIn[$m - 1], $out, $net, $position);
        }
        $cashMonth = $cashTrend[$period->month - 1];

        return new DashboardReport(
            period: $period,
            salesTodayRupees: $this->salesToday($businessId),
            salesMonthRupees: $salesMonth,
            grossProfitMonthRupees: $grossMonth,
            expensesMonthRupees: $expensesMonth,
            netProfitMonthRupees: $netProfitMonth,
            netProfitMarginPercent: $netMargin,
            outstanding: $this->customerOutstanding($businessId),
            productionMonthKg: $this->productionForMonth($businessId, $period->year, $period->month),
            lowStock: $lowStock,
            lowStockCount: count($lowStock),
            productPerformance: $performance,
            highestSellingName: $highestSelling?->name,
            highestProfitName: $highestProfit?->name,
            expenseBreakdown: $this->expensesByCategory($businessId, $period->year, $period->month),
            estimatedCostRevenueRupees: $this->estimatedCostRevenueForMonth($businessId, $period->year, $period->month),
            trend: $trend,
            stockValueRupees: $stockValue,
            stockValuation: $stockValuation,
            supplierOutstanding: $this->suppliers->outstandingSummary($businessId),
            cashInMonthRupees: $cashMonth->cashInRupees,
            supplierPaidMonthRupees: $supplierOut[$period->month - 1],
            expensePaidMonthRupees: $expenseOut[$period->month - 1],
            netCashMonthRupees: $cashMonth->netCashRupees,
            cashPositionRupees: $cashMonth->positionRupees,
            cashTrend: $cashTrend,
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
     * Gross profit per month: revenue − actual COGS, 12 entries Jan..Dec.
     *
     * Costs come from CogsService (production wherever batches exist, the
     * owner's estimate otherwise), so the SQL groups sales by (month, pack) and
     * the money arithmetic stays in bcmath — a per-pack cost cannot be folded
     * into the aggregate without pushing a division into SQL.
     *
     * @return list<string>
     */
    public function grossProfitTrend(string $businessId, int $year): array
    {
        $rows = $this->salesByMonthAndPack($businessId, $year);
        $packCosts = $this->cogs->packCosts($businessId);

        $byMonth = array_fill(1, 12, '0.00');
        foreach ($rows as $r) {
            $profit = bcsub(
                bcadd($r->sales, '0', 2),
                $this->costOf($packCosts, $r->pack_id, (int) $r->qty),
                2,
            );
            $byMonth[(int) $r->m] = bcadd($byMonth[(int) $r->m], $profit, 2);
        }

        return array_values($byMonth);
    }

    /**
     * The month's revenue whose cost is still the owner's estimate rather than
     * actual production. Zero once every product sold has been produced at
     * least once — until then it says how much of gross profit to trust.
     */
    public function estimatedCostRevenueForMonth(string $businessId, int $year, int $month): string
    {
        $packCosts = $this->cogs->packCosts($businessId);

        return collect($this->salesByMonthAndPack($businessId, $year))
            ->filter(fn ($r) => (int) $r->m === $month)
            // Absent from the map = a pack with no sales-year row; treat an
            // unknown pack as estimated, never as silently actual.
            ->filter(fn ($r) => ! ($packCosts[$r->pack_id]->fromProduction ?? false))
            ->reduce(fn (string $carry, $r) => bcadd($carry, $r->sales, 2), '0.00');
    }

    /** Sales grouped by (month, pack) for the year — the shape costing needs. */
    private function salesByMonthAndPack(string $businessId, int $year): \Illuminate\Support\Collection
    {
        return DB::table('sale_lines as sl')
            ->join('sales as s', 's.id', '=', 'sl.sale_id')
            ->where('sl.business_id', $businessId)
            ->whereRaw('extract(year from s.sale_date) = ?', [$year])
            ->groupByRaw('extract(month from s.sale_date), sl.product_pack_id')
            ->selectRaw('extract(month from s.sale_date)::int as m,
                sl.product_pack_id as pack_id,
                sum(sl.qty)::int as qty,
                coalesce(sum(sl.line_total), 0)::text as sales')
            ->get();
    }

    /**
     * qty packs at the pack's cost. An unknown pack costs 0 — the same
     * behaviour a null default_cost_price had before Phase 2b.
     *
     * @param  array<string, PackCost>  $packCosts
     */
    private function costOf(array $packCosts, string $packId, int $qty): string
    {
        return bcmul((string) $qty, $packCosts[$packId]->costRupees ?? '0.00', 2);
    }

    public function expensesForMonth(string $businessId, int $year, int $month): string
    {
        $sum = (string) Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->whereRaw('extract(month from spent_on) = ?', [$month])
            ->selectRaw('coalesce(sum(amount), 0)::text as agg')
            ->value('agg');

        return bcadd($sum, '0', 2);
    }

    /**
     * Operating expenses grouped by category for the month, ordered by the
     * canonical ExpenseCategory order, with zero categories omitted.
     *
     * @return list<ExpenseCategoryTotal>
     */
    public function expensesByCategory(string $businessId, int $year, int $month): array
    {
        $byCategory = Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->whereRaw('extract(month from spent_on) = ?', [$month])
            ->groupBy('category')
            ->selectRaw('category, sum(amount)::text as agg')
            ->pluck('agg', 'category');

        $out = [];
        foreach (ExpenseCategory::keys() as $key) {
            if ($byCategory->has($key)) {
                $out[] = new ExpenseCategoryTotal($key, bcadd((string) $byCategory[$key], '0', 2));
            }
        }

        return $out;
    }

    /** @return list<string> 12 scale-2 decimal strings, index 0 = January. */
    public function expensesTrend(string $businessId, int $year): array
    {
        $byMonth = Expense::query()
            ->where('business_id', $businessId)
            ->whereNull('archived_at')
            ->whereRaw('extract(year from spent_on) = ?', [$year])
            ->selectRaw('extract(month from spent_on)::int as m, coalesce(sum(amount), 0)::text as agg')
            ->groupBy('m')
            ->pluck('agg', 'm');

        return array_map(
            fn (int $m) => bcadd((string) ($byMonth[$m] ?? '0'), '0', 2),
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
     * Per product-pack sales for the year: qty, revenue, cost and margin.
     *
     * Costed through CogsService — the SAME costing the headline gross profit
     * uses, so "highest profit product" can never contradict the P&L above it.
     * Ordered by revenue, highest first.
     *
     * @return list<ProductPerf>
     */
    public function productPerformance(string $businessId, int $year): array
    {
        $packCosts = $this->cogs->packCosts($businessId);

        $rows = DB::table('sale_lines as sl')
            ->join('sales as s', 's.id', '=', 'sl.sale_id')
            ->join('product_packs as pp', 'pp.id', '=', 'sl.product_pack_id')
            ->join('products as prod', 'prod.id', '=', 'pp.product_id')
            ->join('pack_sizes as ps', 'ps.id', '=', 'pp.pack_size_id')
            ->where('sl.business_id', $businessId)
            ->whereRaw('extract(year from s.sale_date) = ?', [$year])
            ->groupBy('pp.id', 'prod.name_en', 'prod.name_hi', 'ps.label')
            ->selectRaw("
                pp.id as pack_id,
                coalesce(prod.name_en, prod.name_hi) || ' ' || ps.label as name,
                sum(sl.qty)::int as qty,
                sum(sl.line_total)::text as sales
            ")
            ->get();

        return $rows
            ->map(function ($r) use ($packCosts) {
                $sales = bcadd($r->sales, '0', 2);
                $cost = $this->costOf($packCosts, $r->pack_id, (int) $r->qty);
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
