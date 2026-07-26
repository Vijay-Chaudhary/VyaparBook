<?php
// app/Reports/CustomerDue.php
namespace App\Reports;

final readonly class CustomerDue
{
    public function __construct(
        public string $customerId,        // uuid, so the dashboard can link to the khata
        public string $name,
        public ?string $village,
        public string $outstandingRupees, // decimal string, may be negative
    ) {}
}
