<?php
// app/Pricing/PriceFloor.php

namespace App\Pricing;

use App\Models\ProductPack;

/**
 * The lowest price a line may be sold at.
 *
 * Callers MUST eager-load `product` and `packSize` on the pack before calling
 * this. This runs once per sale line, so leaving those relations lazy would
 * turn a sale into an N+1. The rule itself must reach the identical answer
 * offline — see resources/js/offline/pricing.js, which implements this same
 * logic. The two are tested against one shared case table; a change to either
 * must be mirrored.
 *
 * Returns a scale-2 decimal string, or null when the pack has no cost basis at
 * all, in which case the line is unbounded (stated in the spec, not silently
 * permissive).
 */
final class PriceFloor
{
    public static function for(ProductPack $pack): ?string
    {
        $cost = $pack->default_cost_price;

        // A zero cost is a real floor of zero; only null/'' means "not set".
        // The '' arm mirrors the JS client's guard, where a blank form field
        // really can produce ''; the decimal:2 cast here never yields '', but
        // the check is kept for symmetry with the offline implementation.
        if ($cost !== null && trim((string) $cost) !== '') {
            return bcadd((string) $cost, '0', 2);
        }

        $perKg = $pack->product?->base_cost_per_kg;
        $weightKg = $pack->packSize?->weight_kg;

        if ($perKg === null || $weightKg === null) {
            return null;
        }

        // decimal(10,2) x decimal(8,3) is exact at scale 5; scale 6 is headroom.
        return self::ceilToPaisa(bcmul((string) $perKg, (string) $weightKg, 6));
    }

    /**
     * Round UP to the paisa so the floor never lands below true cost.
     *
     * Truncation toward zero only equals rounding up because costs are
     * non-negative by domain; this would need a sign check to hold in general.
     */
    private static function ceilToPaisa(string $value): string
    {
        $truncated = bcadd($value, '0', 2);   // bcadd truncates toward zero

        return bccomp($truncated, $value, 6) === 0 ? $truncated : bcadd($truncated, '0.01', 2);
    }
}
