<?php
// app/Reports/OutstandingSummary.php
namespace App\Reports;

final readonly class OutstandingSummary
{
    /** @param list<CustomerDue> $customers */
    public function __construct(
        public string $totalRupees,
        public array $customers,
    ) {}
}
