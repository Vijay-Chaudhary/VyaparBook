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
     */
    public function __construct(
        public ReportPeriod $period,
        public string $salesTodayRupees,
        public string $salesMonthRupees,
        public string $estGrossProfitMonthRupees, // sales − est. product cost, before expenses
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
        public array $trend,
    ) {}
}
