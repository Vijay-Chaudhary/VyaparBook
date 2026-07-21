<?php
// app/Reports/CustomerDue.php
namespace App\Reports;

final readonly class CustomerDue
{
    public function __construct(
        public string $name,
        public ?string $village,
        public string $outstandingRupees, // decimal string, may be negative
    ) {}
}
