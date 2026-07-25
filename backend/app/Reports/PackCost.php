<?php
// app/Reports/PackCost.php
namespace App\Reports;

final readonly class PackCost
{
    public function __construct(
        public string $costRupees,   // what one pack cost to make (or the estimate)
        public bool $fromProduction, // false = still the owner-typed estimate
    ) {}
}
