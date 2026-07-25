/**
 * The lowest price a sale line may be sold at.
 *
 * This mirrors App\Pricing\PriceFloor on the server, deliberately: the floor
 * has to be enforced on the phone so a salesman can renegotiate while the
 * customer is still standing there, and on the server because a rule enforced
 * only on a client is not enforced. The two are tested against one shared case
 * table — see pricing.test.js and tests/Unit/PriceFloorTest.php.
 *
 * Works in integer paise throughout; money never touches a float.
 *
 * Exactness holds across every price and pack weight a distributor would enter.
 * The multiply-then-divide below is Number arithmetic, so it would drift past
 * 2^53 — reachable only by pairing the very top of decimal(10,2) with the very
 * top of decimal(8,3), which no pack of namkeen is. The PHP twin uses bcmul and
 * is exact over the whole column domain; if this ever prices bulk commodities,
 * revisit here first.
 */

import { toPaise } from './money';

/** The floor in paise, or null when the pack has no cost basis at all. */
export function floorPaise(pack, product) {
    const cost = pack?.default_cost_price;

    // A zero cost is a real floor of zero; only null/undefined/'' means unset.
    if (cost !== null && cost !== undefined && String(cost).trim() !== '') {
        return toPaise(cost);
    }

    const perKg = product?.base_cost_per_kg;
    const weightKg = pack?.weight_kg;

    if (perKg === null || perKg === undefined || weightKg === null || weightKg === undefined) {
        return null;
    }

    // Integer arithmetic: weight has 3 decimals, so scale it to whole grams
    // rather than multiplying by a fraction and inheriting float error.
    const grams = Math.round(Number(weightKg) * 1000);

    return Math.ceil((toPaise(perKg) * grams) / 1000);
}

/** True when the rate is under the floor. Equal to the floor is allowed. */
export function belowFloor(ratePaise, floor) {
    if (floor === null || floor === undefined) return false;

    return ratePaise < floor;
}
