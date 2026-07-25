<?php
// app/Gst/GstSplit.php

namespace App\Gst;

/**
 * The tax breakdown of one GST-inclusive amount.
 *
 * taxableValue + cgst + sgst == the original amount, EXACTLY — that identity is
 * what lets an invoice agree with the sale (and therefore the khata) to the
 * paisa. See GstCalculator.
 */
final readonly class GstSplit
{
    public function __construct(
        public string $lineTotal,     // the GST-inclusive amount we started from
        public string $taxableValue,
        public string $tax,           // cgst + sgst
        public string $cgst,
        public string $sgst,
        public string $ratePercent,
    ) {}
}
