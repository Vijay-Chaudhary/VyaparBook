<?php
// app/Reports/DashboardReport.php
namespace App\Reports;

final readonly class DashboardReport
{
    /**
     * @param list<LowStockRow>            $lowStock
     * @param list<ProductPerf>            $productPerformance
     * @param list<ExpenseCategoryTotal>   $expenseBreakdown
     * @param list<TrendRow>               $trend  exactly 12 rows, Jan..Dec
     * @param list<StockValuationRow>      $stockValuation
     * @param list<CashFlowRow>            $cashTrend  exactly 12 rows, Jan..Dec
     * @param list<FinishedGoodsRow>      $finishedGoods  on-hand per product, biggest first
     */
    public function __construct(
        public ReportPeriod $period,
        public string $salesTodayRupees,
        public string $salesMonthRupees,
        public string $grossProfitMonthRupees,      // sales − actual COGS, before expenses
        public string $expensesMonthRupees,
        public string $netProfitMonthRupees,       // gross − expenses (may be negative)
        public string $netProfitMarginPercent,     // net ÷ sales × 100, one decimal; '0.0' when sales are 0
        public OutstandingSummary $outstanding,
        public string $productionMonthKg,
        public array $lowStock,
        public int $lowStockCount,
        public array $productPerformance,
        public ?string $highestSellingName,
        public ?string $highestProfitName,
        public array $expenseBreakdown,
        // Phase 2b: how much of this month's revenue is still costed from the
        // owner's estimate rather than actual production. '0.00' = fully actual.
        public string $estimatedCostRevenueRupees,
        public array $trend,
        // Phase 2a: raw-material stock valued at weighted-average purchase cost,
        // and what is owed to suppliers (the buy-side mirror of $outstanding).
        public string $stockValueRupees,
        public array $stockValuation,
        public SupplierOutstandingSummary $supplierOutstanding,
        // Phase 3: cash flow — money actually collected vs. actually paid out.
        // Position is running net-cash-since-inception, NOT a bank balance
        // (there is no stored opening-cash figure). netCash/position may be < 0.
        public string $cashInMonthRupees,
        public string $supplierPaidMonthRupees,
        public string $expensePaidMonthRupees,
        public string $netCashMonthRupees,
        public string $cashPositionRupees,
        public array $cashTrend,   // list<CashFlowRow>, exactly 12 rows Jan..Dec
        // Finished goods: produced kg minus sold kg per product, since
        // inception. onHandKg may be negative — see FinishedGoodsRow.
        public array $finishedGoods,
    ) {}
}
