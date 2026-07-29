<?php
// app/Orders/OrderAdjustment.php

namespace App\Orders;

use App\Models\OrderLine;

/**
 * What an owner changed when they accepted an order.
 *
 * Pure and DB-free, like OrderStatus: it compares the originals stamped at
 * creation against the live values, so nothing about "was this edited?" is
 * stored and nothing can drift out of step with the rows it describes.
 *
 * A null original means UNKNOWN — the line predates the audit trail — and is
 * deliberately reported as "not changed" so no caller ever renders a claim the
 * data does not support. Silence here means "nothing to show", never "we
 * checked and it was identical".
 */
final class OrderAdjustment
{
    /**
     * Did acceptance change this line?
     *
     * A difference in EITHER qty or rate counts: a shop told "eight instead of
     * ten" and a shop told "₹95 instead of ₹90" have both been renegotiated.
     */
    public static function changed(OrderLine $line): bool
    {
        if ($line->ordered_qty === null || $line->ordered_rate === null) {
            return false;
        }

        return (int) $line->ordered_qty !== (int) $line->qty
            // bccomp, not !==: decimal casts stringify at differing precision,
            // so '90.00' and '90.0' are the same money and must not read as an
            // edit that never happened.
            || bccomp((string) $line->ordered_rate, (string) $line->rate, 2) !== 0;
    }

    /**
     * What the order came to as it was taken, or null if any line's original is
     * unknown.
     *
     * All-or-nothing on purpose: a total mixing originals with post-acceptance
     * values is not a figure that was ever true, and showing it beside the
     * accepted total would invite exactly the wrong subtraction.
     *
     * @param  iterable<OrderLine>  $lines
     */
    public static function originalTotal(iterable $lines): ?string
    {
        $total = '0.00';

        foreach ($lines as $line) {
            if ($line->ordered_qty === null || $line->ordered_rate === null) {
                return null;
            }

            $total = bcadd($total, bcmul((string) $line->ordered_rate, (string) (int) $line->ordered_qty, 2), 2);
        }

        return $total;
    }

    /** Did acceptance change anything at all about this set of lines? */
    public static function anyChanged(iterable $lines): bool
    {
        foreach ($lines as $line) {
            if (self::changed($line)) {
                return true;
            }
        }

        return false;
    }
}
