<?php
// app/Reports/FinishedGoodsRow.php

namespace App\Reports;

/**
 * On-hand finished goods for one product.
 *
 * Weight, not pack count: production records bulk kg and packing happens on the
 * way out, so kg is what the existing data can answer truthfully. All figures
 * are bcmath scale-3 decimal strings; onHandKg MAY be negative, which means
 * more was sold than was ever recorded as produced — a data signal worth
 * showing rather than clamping away.
 */
final readonly class FinishedGoodsRow
{
    public function __construct(
        public string $productId,
        public string $name,
        public string $producedKg,
        public string $soldKg,
        public string $onHandKg,
    ) {}
}
