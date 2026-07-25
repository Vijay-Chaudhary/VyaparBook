import { describe, expect, it } from 'vitest';
import { productName } from './catalog';

describe('productName', () => {
    const sev = { id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' };

    it('shows the English name to an English reader', () => {
        expect(productName(sev, 'en')).toBe('Sev Mix');
    });

    it('shows the Hindi name to a Hindi reader', () => {
        expect(productName(sev, 'hi')).toBe('सेव मिक्स');
    });

    it('falls back to Hindi when a product has no English name', () => {
        // name_en is nullable in the schema; name_hi is NOT NULL, so this is
        // the common case for a shop that never filled in English names.
        expect(productName({ id: 'p2', name_en: null, name_hi: 'नमकीन' }, 'en')).toBe('नमकीन');
    });

    it('falls back to English when a Hindi name is somehow blank', () => {
        expect(productName({ id: 'p3', name_en: 'Bhujia', name_hi: '' }, 'hi')).toBe('Bhujia');
    });

    it('treats a whitespace-only name as missing rather than rendering blanks', () => {
        expect(productName({ id: 'p4', name_en: '   ', name_hi: 'भुजिया' }, 'en')).toBe('भुजिया');
    });

    it('returns an empty string for an unknown product instead of "undefined"', () => {
        // The bug this guards: a missing lookup rendered nothing at all, leaving
        // the dropdown showing only size and price.
        expect(productName(undefined, 'en')).toBe('');
        expect(productName(null, 'hi')).toBe('');
    });

    it('returns an empty string when a product carries no usable name at all', () => {
        expect(productName({ id: 'p5', name_en: null, name_hi: null }, 'en')).toBe('');
    });

    it('defaults to English when no locale is given', () => {
        expect(productName(sev)).toBe('Sev Mix');
    });
});
