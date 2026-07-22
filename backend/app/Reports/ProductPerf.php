<?php
// app/Reports/ProductPerf.php
namespace App\Reports;

final readonly class ProductPerf
{
    public function __construct(
        public string $name,
        public int $qtySold,
        public string $salesRupees,
        public string $estCostRupees,
        public string $estProfitRupees,
        public string $marginPercent, // "4.9" (one decimal), "0.0" when sales are 0
    ) {}
}
