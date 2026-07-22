<?php
// app/Reports/ReportPeriod.php

namespace App\Reports;

use Illuminate\Support\Carbon;

/**
 * The month/year a dashboard is being viewed for. Constructed from raw request
 * input, so it validates and clamps rather than trusting: a hand-edited query
 * string can never push the service outside a sane window.
 */
final readonly class ReportPeriod
{
    private function __construct(
        public int $year,
        public int $month,
    ) {}

    public static function fromInput(?int $year, ?int $month): self
    {
        $now = Carbon::now();
        $currentYear = (int) $now->year;

        $year ??= $currentYear;
        $month ??= (int) $now->month;

        $year = max(2020, min($currentYear, $year));
        $month = max(1, min(12, $month));

        return new self($year, $month);
    }

    /** First day of the selected month, for range queries. */
    public function startOfMonth(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)->startOfDay();
    }

    public function endOfMonth(): Carbon
    {
        return $this->startOfMonth()->endOfMonth();
    }
}
