import { describe, expect, it } from 'vitest';
import { belowFloor, floorPaise, needsCostConfirmation, readRatePaise, sendableRate } from './pricing';

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

describe('readRatePaise', () => {
    it('reads a normal rate', () => {
        expect(readRatePaise('105.00')).toBe(10500);
    });

    it('treats blank as zero rather than unreadable', () => {
        // An empty field is a rate not yet typed, not a broken one.
        expect(readRatePaise('')).toBe(0);
        expect(readRatePaise(undefined)).toBe(0);
    });

    it('accepts a rate still being typed', () => {
        expect(readRatePaise('12.')).toBe(1200);
    });

    it('returns null for text that is not money, so submit can refuse it', () => {
        expect(readRatePaise('abc')).toBeNull();
        expect(readRatePaise('12..3')).toBeNull();
        expect(readRatePaise('1e5')).toBeNull();
    });

    it('parses a lone minus sign rather than throwing', () => {
        // toPaise's regex accepts a bare sign, so this is -0, not unreadable.
        // It is still not submittable — see sendableRate, which rejects
        // anything negative as well as anything unreadable.
        expect(readRatePaise('-')).toBe(-0);
    });
});

describe('sendableRate', () => {
    it('accepts an ordinary rate', () => {
        expect(sendableRate('105.00')).toBe(true);
    });

    it('accepts a blank rate, which the server reads as the shop default', () => {
        expect(sendableRate('')).toBe(true);
    });

    it('refuses text that is not money', () => {
        expect(sendableRate('abc')).toBe(false);
        expect(sendableRate('12..3')).toBe(false);
    });

    it('refuses a negative rate — a return is a negative qty, not a negative price', () => {
        expect(sendableRate('-5.00')).toBe(false);
        expect(sendableRate('-')).toBe(false);
    });
});

describe('needsCostConfirmation', () => {
    it('asks when any line is under cost', () => {
        // Below cost is allowed now, but never by accident — a mis-keyed ₹9 for
        // ₹90 lands below almost any floor, which the old hard block caught.
        expect(needsCostConfirmation([null, 9000, null])).toBe(true);
    });

    it('does not ask when every line clears cost', () => {
        expect(needsCostConfirmation([null, null])).toBe(false);
    });

    it('does not ask for an empty or missing set', () => {
        expect(needsCostConfirmation([])).toBe(false);
        expect(needsCostConfirmation(undefined)).toBe(false);
    });

    it('treats a zero-paise floor as a real floor, not an absent one', () => {
        // A free-issue pack costs nothing, so anything at all clears it; the
        // violation list only ever holds a floor when the rate was under it.
        expect(needsCostConfirmation([0])).toBe(true);
    });
});
