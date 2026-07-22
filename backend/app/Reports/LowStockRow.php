<?php
// app/Reports/LowStockRow.php
namespace App\Reports;

final readonly class LowStockRow
{
    public function __construct(
        public string $name,
        public string $onHand,   // scale-3 decimal string
        public string $reorder,  // scale-3 decimal string
    ) {}
}
