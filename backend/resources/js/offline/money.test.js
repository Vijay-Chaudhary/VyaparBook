import { describe, expect, it } from 'vitest';
import { formatRupees, sumPaise, toDecimal, toPaise } from './money';

describe('parsing', () => {
    it.each([
        ['0', 0],
        ['0.00', 0],
        ['1', 100],
        ['1.5', 150],
        ['250.50', 25050],
        ['1200.05', 120005],
        ['-99.99', -9999],
        ['', 0],
        [null, 0],
        [undefined, 0],
    ])('parses %s to %i paise', (input, expected) => {
        expect(toPaise(input)).toBe(expected);
    });

    it('truncates beyond two places, as bcmath does', () => {
        // The server discards beyond scale rather than rounding up; rounding
        // here would make the client disagree by a paisa.
        expect(toPaise('1.999')).toBe(199);
    });

    it('rejects nonsense rather than silently returning zero', () => {
        expect(() => toPaise('abc')).toThrow();
    });
});

describe('formatting', () => {
    it.each([
        [0, '0.00'],
        [25050, '250.50'],
        [100, '1.00'],
        [5, '0.05'],
        [-9999, '-99.99'],
    ])('formats %i paise as %s', (paise, expected) => {
        expect(toDecimal(paise)).toBe(expected);
    });

    it('round-trips every value exactly', () => {
        for (const value of ['0.00', '0.05', '99.99', '250.50', '123456.78']) {
            expect(toDecimal(toPaise(value))).toBe(value);
        }
    });

    it('uses Indian digit grouping', () => {
        // ₹1,20,000.00 — not ₹120,000.00. The Western grouping reads as a
        // different amount to the person whose money it is.
        expect(formatRupees(12000000)).toBe('₹1,20,000.00');
        expect(formatRupees(100000)).toBe('₹1,000.00');
        expect(formatRupees(10000000)).toBe('₹1,00,000.00');
    });
});

describe('exactness', () => {
    /**
     * The reason this module exists. These sums are the ones that drift when
     * done with JavaScript floats, and a khata that disagrees with the printed
     * statement is a khata nobody trusts.
     */
    it('does not drift where floats would', () => {
        expect(0.1 + 0.2).not.toBe(0.3); // the hazard, demonstrated
        expect(toPaise('0.1') + toPaise('0.2')).toBe(toPaise('0.3'));
    });

    it('stays exact over a long run of awkward amounts', () => {
        const amounts = Array.from({ length: 1000 }, () => '0.07');
        const total = sumPaise(amounts.map(toPaise));

        expect(toDecimal(total)).toBe('70.00');

        // The float equivalent does not land on 70 exactly.
        const floatTotal = amounts.reduce((sum, a) => sum + Number(a), 0);
        expect(floatTotal).not.toBe(70);
    });
});
