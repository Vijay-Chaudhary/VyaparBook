<?php
// app/Reports/SupplierOutstandingSummary.php
namespace App\Reports;

final readonly class SupplierOutstandingSummary
{
    /** @param list<SupplierDue> $suppliers */
    public function __construct(
        public string $totalRupees,
        public array $suppliers,
    ) {}
}
