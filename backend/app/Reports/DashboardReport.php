<?php
// app/Reports/DashboardReport.php
namespace App\Reports;

final readonly class DashboardReport
{
    /**
     * @param list<LowStockRow>  $lowStock
     * @param list<ProductPerf>  $productPerformance
     * @param list<TrendRow>     $trend  exactly 12 rows, Jan..Dec
     */
    public function __construct(
        public ReportPeriod $period,
        public string $salesTodayRupees,
        public string $salesMonthRupees,
        public OutstandingSummary $outstanding,
        public string $productionMonthKg,
        public array $lowStock,
        public int $lowStockCount,
        public array $productPerformance,
        public ?string $highestSellingName,
        public ?string $highestProfitName,
        public array $trend,
    ) {}
}
