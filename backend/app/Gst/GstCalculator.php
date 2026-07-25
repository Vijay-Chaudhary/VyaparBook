<?php
// app/Gst/GstCalculator.php

namespace App\Gst;

/**
 * The GST rulebook: extracting tax from GST-INCLUSIVE prices.
 *
 * Indian retail prices already include tax, and this app's sale totals are what
 * the customer was actually charged — khata outstanding is built on them. So an
 * invoice must work BACKWARDS out of the amount charged rather than adding tax
 * on top, or issuing an invoice would silently change what someone owes.
 *
 *     taxable = amount ÷ (1 + rate/100)
 *     tax     = amount − taxable          (by subtraction, so nothing is lost)
 *     cgst + sgst = tax                   (halves, odd paisa to CGST)
 *
 * Deriving the tax by subtraction rather than by its own division is what makes
 * taxable + cgst + sgst == amount hold exactly, at every rate and every
 * awkward figure. Pure and DB-free so the whole rulebook is testable alone.
 *
 * Intra-state only (CGST + SGST). IGST is a later phase.
 */
final class GstCalculator
{
    public static function extract(string $lineTotal, string $ratePercent): GstSplit
    {
        $lineTotal = bcadd($lineTotal, '0', 2);

        if (bccomp($ratePercent, '0', 4) === 0) {
            // Exempt goods: the whole amount is taxable value and there is no tax.
            return new GstSplit($lineTotal, $lineTotal, '0.00', '0.00', '0.00', bcadd($ratePercent, '0', 2));
        }

        $divisor = bcadd('1', bcdiv($ratePercent, '100', 8), 8);
        $taxable = self::round(bcdiv($lineTotal, $divisor, 8));

        // Subtraction, not a second division: the two must not disagree by a paisa.
        $tax = bcsub($lineTotal, $taxable, 2);

        // bcdiv truncates toward zero, so the remainder lands on CGST — and the
        // halves still sum to the tax exactly, which is what matters.
        $sgst = bcdiv($tax, '2', 2);
        $cgst = bcsub($tax, $sgst, 2);

        return new GstSplit($lineTotal, $taxable, $tax, $cgst, $sgst, bcadd($ratePercent, '0', 2));
    }

    /** Half-up at scale 2; bcadd truncates, so the nudge does the rounding. */
    private static function round(string $value): string
    {
        return bcadd($value, bccomp($value, '0', 8) < 0 ? '-0.005' : '0.005', 2);
    }
}
