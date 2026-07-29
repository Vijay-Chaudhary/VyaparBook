<?php
// app/Pricing/Margin.php

namespace App\Pricing;

/**
 * What a pack makes, or loses, at its default selling price.
 *
 * Pure and DB-free, like PriceFloor and OrderStatus. This exists because the
 * cost floor stopped refusing below-cost prices (F-16): a shop that may now
 * sell under cost has to be able to SEE which packs do, and 11 of this shop's
 * 21 packs do at their true costs.
 *
 * Both figures are computed in bcmath, never floats — a margin is money, and
 * the rest of this codebase does not let rupees drift.
 */
final class Margin
{
    /**
     * Absolute margin: sell − cost. Null when there is no cost basis, which
     * means unknown rather than "everything is profit".
     */
    public static function amount(?string $sell, ?string $cost): ?string
    {
        if ($sell === null || $cost === null) {
            return null;
        }

        return bcsub($sell, $cost, 2);
    }

    /**
     * Margin as a percentage OF THE SELLING PRICE, to 1 decimal place.
     *
     * Percent-of-sell, not markup-on-cost: the two differ (a pack bought at 80
     * and sold at 100 is 20% margin but 25% markup) and margin-on-revenue is
     * what a P&L reads. Null when unknown, and also when the selling price is
     * zero — a free issue has no percentage, and dividing would blow up rather
     * than say so.
     */
    public static function percent(?string $sell, ?string $cost): ?string
    {
        $amount = self::amount($sell, $cost);

        if ($amount === null || bccomp($sell, '0', 2) === 0) {
            return null;
        }

        // Divide at scale 5 so the 1-dp result is not truncated twice over.
        // bcmath truncates toward zero rather than rounding, so 16.66% reads
        // 16.6% and -6.36% reads -6.3% — the displayed percentage is a guide,
        // and the exact rupee amount sits next to it in the same column.
        return bcadd(bcmul(bcdiv($amount, $sell, 5), '100', 3), '0', 1);
    }

    /** Is this pack sold at a loss? Unknown cost is not a loss. */
    public static function isLoss(?string $sell, ?string $cost): bool
    {
        $amount = self::amount($sell, $cost);

        return $amount !== null && bccomp($amount, '0', 2) < 0;
    }
}
