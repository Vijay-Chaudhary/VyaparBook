import { beforeEach, describe, expect, it, vi } from 'vitest';
import { beatsForDay, isoWeekday } from './beats';

/** A stand-in Dexie: the module only ever reads three tables. */
const tables = { beats: [], beat_customers: [], customers: [] };

vi.mock('./db', () => ({
    currentDb: () => ({
        beats: { toArray: async () => tables.beats },
        beat_customers: { toArray: async () => tables.beat_customers },
        customers: { toArray: async () => tables.customers },
    }),
}));

beforeEach(() => {
    tables.beats = [];
    tables.beat_customers = [];
    tables.customers = [
        { id: 'c1', name: 'First' },
        { id: 'c2', name: 'Second' },
        { id: 'c3', name: 'Third' },
    ];
});

describe('isoWeekday', () => {
    it('maps Sunday to 7 rather than 0, matching the server', () => {
        expect(isoWeekday(new Date('2026-07-26T10:00:00'))).toBe(7); // Sunday
        expect(isoWeekday(new Date('2026-07-27T10:00:00'))).toBe(1); // Monday
    });
});

describe('beatsForDay', () => {
    const monday = new Date('2026-07-27T10:00:00');

    it('returns only beats that run today', async () => {
        tables.beats = [
            { id: 'b1', name: 'Rampur', weekdays: [1, 4], assigned_user_id: 7 },
            { id: 'b2', name: 'Sitapur', weekdays: [2], assigned_user_id: 7 },
        ];

        const result = await beatsForDay(7, monday);

        expect(result.map((b) => b.name)).toEqual(['Rampur']);
    });

    it('lists customers in call order', async () => {
        tables.beats = [{ id: 'b1', name: 'Rampur', weekdays: [1], assigned_user_id: 7 }];
        tables.beat_customers = [
            { id: 'l3', beat_id: 'b1', customer_id: 'c3', position: 3 },
            { id: 'l1', beat_id: 'b1', customer_id: 'c1', position: 1 },
            { id: 'l2', beat_id: 'b1', customer_id: 'c2', position: 2 },
        ];

        const [beat] = await beatsForDay(7, monday);

        expect(beat.customers.map((c) => c.name)).toEqual(['First', 'Second', 'Third']);
    });

    it('hides another salesman\'s beat but keeps unassigned ones', async () => {
        tables.beats = [
            { id: 'b1', name: 'Mine', weekdays: [1], assigned_user_id: 7 },
            { id: 'b2', name: 'Theirs', weekdays: [1], assigned_user_id: 9 },
            // A small shop's owner often works a route themselves; hiding this
            // would leave the day looking empty.
            { id: 'b3', name: 'Unassigned', weekdays: [1], assigned_user_id: null },
        ];

        const result = await beatsForDay(7, monday);

        expect(result.map((b) => b.name)).toEqual(['Mine', 'Unassigned']);
    });

    it('ignores an archived beat once the server marks it', async () => {
        tables.beats = [
            { id: 'b1', name: 'Old', weekdays: [1], assigned_user_id: 7, archived_at: '2026-07-01T00:00:00Z' },
        ];

        expect(await beatsForDay(7, monday)).toEqual([]);
    });

    it('skips a link whose customer is not cached rather than rendering a blank row', async () => {
        tables.beats = [{ id: 'b1', name: 'Rampur', weekdays: [1], assigned_user_id: 7 }];
        tables.beat_customers = [
            { id: 'l1', beat_id: 'b1', customer_id: 'c1', position: 1 },
            { id: 'l9', beat_id: 'b1', customer_id: 'missing', position: 2 },
        ];

        const [beat] = await beatsForDay(7, monday);

        expect(beat.customers.map((c) => c.name)).toEqual(['First']);
    });

    it('returns nothing on a day with no beats', async () => {
        tables.beats = [{ id: 'b1', name: 'Rampur', weekdays: [2], assigned_user_id: 7 }];

        expect(await beatsForDay(7, monday)).toEqual([]);
    });
});
