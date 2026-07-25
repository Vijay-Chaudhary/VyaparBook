<?php
// app/Reports/CashFlowRow.php
namespace App\Reports;

/**
 * One month of cash flow for the dashboard cash section (Phase 3).
 * All figures are scale-2 bcmath decimal strings; netCash and position may be
 * negative. position is running (seeded from CashFlowService::openingPosition),
 * so it is net-cash-since-inception, not a bank balance.
 */
final readonly class CashFlowRow
{
    public function __construct(
        public int $month,             // 1..12
        public string $cashInRupees,   // customer collections
        public string $cashOutRupees,  // supplier payments + operating expenses
        public string $netCashRupees,  // in − out (may be negative)
        public string $positionRupees, // running cumulative net (may be negative)
    ) {}
}
