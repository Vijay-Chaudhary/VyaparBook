import { describe, expect, it } from 'vitest';
import { formatQty, sumMilli, toDecimal, toMilli } from './qty';

describe('parsing quantities', () => {
    it.each([
        ['0', 0],
        ['1', 1000],
        ['1.5', 1500],
        ['0.250', 250],
        ['1.250', 1250],
        ['-0.5', -500],
        ['', 0],
        [null, 0],
    ])('parses %s to %i milliunits', (input, expected) => {
        expect(toMilli(input)).toBe(expected);
    });

    it('truncates beyond three places, matching bcmath', () => {
        expect(toMilli('1.2345')).toBe(1234);
    });
});

describe('formatting quantities', () => {
    it.each([
        [0, '0'],
        [1000, '1'],
        [1500, '1.5'],
        [1250, '1.25'],
        [250, '0.25'],
        [-500, '-0.5'],
    ])('formats %i milliunits as %s', (milli, expected) => {
        // Trailing zeros trimmed, no bare trailing dot.
        expect(toDecimal(milli)).toBe(expected);
    });

    it('appends the unit for display', () => {
        expect(formatQty(1500, 'kg')).toBe('1.5 kg');
        expect(formatQty(1000, 'litre')).toBe('1 litre');
    });
});

describe('exactness', () => {
    it('sums awkward weights without drift', () => {
        // 0.1 + 0.2 in floats is 0.30000000000000004; on-hand must be exact.
        expect(toMilli('0.1') + toMilli('0.2')).toBe(toMilli('0.3'));

        const weights = Array.from({ length: 100 }, () => '0.001');
        expect(toDecimal(sumMilli(weights.map(toMilli)))).toBe('0.1');
    });
});
