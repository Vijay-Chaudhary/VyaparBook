import { describe, expect, it } from 'vitest';
import { customerPath } from './router';

/**
 * Every list that names a customer should be a way into their khata, so the
 * destination is decided in one place rather than re-spelled at each call site.
 *
 * The null cases are the point: an order can name a customer the cache has not
 * pulled yet, and a link to /khata/undefined would take a shopkeeper to an
 * empty ledger that looks like lost data.
 */
describe('customerPath', () => {
    it('points at the customer ledger', () => {
        expect(customerPath({ uuid: 'c-1' })).toBe('/khata/c-1');
    });

    it('has nowhere to point when the customer is not in the cache', () => {
        expect(customerPath(undefined)).toBeNull();
        expect(customerPath(null)).toBeNull();
    });

    it('has nowhere to point when the row carries no uuid', () => {
        // A row joined from the server by numeric id only; routing keys on uuid.
        expect(customerPath({ id: 7, name: 'Ramesh' })).toBeNull();
    });

    it('escapes the uuid so a stray character cannot break out of the segment', () => {
        expect(customerPath({ uuid: 'a/b' })).toBe('/khata/a%2Fb');
    });
});
