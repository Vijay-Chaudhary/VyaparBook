import { describe, expect, it } from 'vitest';
import { formatDate, setLocale } from './i18n';

/**
 * Dates reach the UI in two different shapes and both must render, because a
 * screen showing one of them raw is what this file was written after: the
 * Orders screen printed '2026-07-26T00:00:00.000000Z' at a salesman.
 */
describe('formatDate', () => {
    it('formats a date a server row carries, microseconds and all', () => {
        setLocale('en');

        expect(formatDate('2026-07-26T00:00:00.000000Z')).toBe('26 Jul 2026');
    });

    it('formats the plain date a queued row carries', () => {
        // An order still in the outbox holds what the form submitted.
        setLocale('en');

        expect(formatDate('2026-07-26')).toBe('26 Jul 2026');
    });

    it('formats in the reader\'s language', () => {
        setLocale('hi');

        expect(formatDate('2026-07-26')).toContain('2026');
        expect(formatDate('2026-07-26')).not.toBe('26 Jul 2026');

        setLocale('en');
    });

    it('returns an empty string for nothing, rather than "Invalid Date"', () => {
        expect(formatDate(null)).toBe('');
        expect(formatDate(undefined)).toBe('');
        expect(formatDate('')).toBe('');
    });

    it('falls back to the raw value it cannot parse, never "Invalid Date"', () => {
        expect(formatDate('not a date')).toBe('not a date');
    });
});
