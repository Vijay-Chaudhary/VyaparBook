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
