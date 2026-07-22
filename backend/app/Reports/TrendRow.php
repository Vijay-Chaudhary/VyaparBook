<?php
// app/Reports/TrendRow.php
namespace App\Reports;

final readonly class TrendRow
{
    public function __construct(
        public int $month,                // 1..12
        public string $salesRupees,
        public string $productionKg,      // scale-3 decimal string
        public string $grossProfitRupees, // estimated: sales − product cost
        public string $expensesRupees,    // operating expenses that month
        public string $netProfitRupees,   // gross − expenses (may be negative)
    ) {}
}
