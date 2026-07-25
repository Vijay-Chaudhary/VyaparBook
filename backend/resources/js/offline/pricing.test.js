import { describe, expect, it } from 'vitest';
import { belowFloor, floorPaise } from './pricing';

/**
 * THE SHARED CASE TABLE — the mirror of tests/Unit/PriceFloorTest.php.
 * A case added there must be added here. Values are in paise.
 */
const pack = (over = {}) => ({
    default_cost_price: null,
    weight_kg: '0.500',
    ...over,
});

const product = (over = {}) => ({ base_cost_per_kg: null, ...over });

describe('floorPaise', () => {
    it('uses the pack cost price when it is set', () => {
        expect(floorPaise(pack({ default_cost_price: '92.00' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(9200);
    });

    it('derives the floor from cost-per-kg and pack weight when no cost price is set', () => {
        expect(floorPaise(pack({ weight_kg: '0.500' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(9000);
    });

    it('rounds a derived floor UP to the paisa', () => {
        // 181.00 x 0.333 = 60.273 -> 6028 paise
        expect(floorPaise(pack({ weight_kg: '0.333' }), product({ base_cost_per_kg: '181.00' })))
            .toBe(6028);
    });

    it('does not round up a derived floor that is already exact', () => {
        expect(floorPaise(pack({ weight_kg: '0.333' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(5994);
    });

    it('treats a zero cost price as a real floor of zero, not as missing', () => {
        expect(floorPaise(pack({ default_cost_price: '0.00' }), product({ base_cost_per_kg: '180.00' })))
            .toBe(0);
    });

    it('returns null when there is neither a cost price nor a cost per kg', () => {
        expect(floorPaise(pack(), product())).toBeNull();
    });

    // JS-only: PHP's PriceFloor::for(ProductPack) is non-nullable, so its type
    // system rules this case out. The rest of the table is shared.
    it('returns null when the pack or product is missing entirely', () => {
        expect(floorPaise(undefined, product({ base_cost_per_kg: '180.00' }))).toBeNull();
        expect(floorPaise(pack({ weight_kg: '0.500' }), undefined)).toBeNull();
    });
});

describe('belowFloor', () => {
    it('rejects a rate under the floor', () => {
        expect(belowFloor(8999, 9000)).toBe(true);
    });

    it('allows a rate exactly at the floor — selling at cost is a real decision', () => {
        expect(belowFloor(9000, 9000)).toBe(false);
    });

    it('allows anything when there is no floor', () => {
        expect(belowFloor(1, null)).toBe(false);
    });
});
