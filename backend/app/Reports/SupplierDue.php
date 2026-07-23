<?php
// app/Reports/SupplierDue.php
namespace App\Reports;

final readonly class SupplierDue
{
    public function __construct(
        public string $name,
        public ?string $village,
        public string $outstandingRupees, // decimal string, may be negative (advance)
    ) {}
}
