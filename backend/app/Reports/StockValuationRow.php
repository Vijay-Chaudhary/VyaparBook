<?php
// app/Reports/StockValuationRow.php
namespace App\Reports;

final readonly class StockValuationRow
{
    public function __construct(
        public string $name,
        public string $onHand,         // scale-3 kg string
        public string $costPerKgRupees, // scale-2 weighted-avg cost, '0.00' if never purchased
        public string $valueRupees,     // scale-2 = onHand × costPerKg
    ) {}
}
