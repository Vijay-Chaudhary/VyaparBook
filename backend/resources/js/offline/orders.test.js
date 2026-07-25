import { describe, expect, it } from 'vitest';
import { actionsFor, groupByStatus } from './orders';

describe('actionsFor', () => {
    it('offers nothing while the owner has not decided', () => {
        // The sync boundary made visible: until the acceptance arrives, the
        // salesman genuinely does not know whether to pack.
        expect(actionsFor('pending')).toEqual(['cancel']);
    });

    it('offers packing once accepted', () => {
        expect(actionsFor('accepted')).toEqual(['pack', 'cancel']);
    });

    it('offers delivery once packed', () => {
        expect(actionsFor('packed')).toEqual(['deliver', 'cancel']);
    });

    it('offers nothing on a finished order', () => {
        expect(actionsFor('delivered')).toEqual([]);
        expect(actionsFor('rejected')).toEqual([]);
        expect(actionsFor('cancelled')).toEqual([]);
    });

    it('offers nothing for a status it does not know', () => {
        expect(actionsFor('banana')).toEqual([]);
    });
});

describe('groupByStatus', () => {
    it('groups orders under their status, newest first within each', () => {
        const grouped = groupByStatus([
            { id: 'a', status: 'pending', order_date: '2026-07-01' },
            { id: 'b', status: 'packed', order_date: '2026-07-03' },
            { id: 'c', status: 'pending', order_date: '2026-07-05' },
        ]);

        expect(grouped.pending.map((o) => o.id)).toEqual(['c', 'a']);
        expect(grouped.packed.map((o) => o.id)).toEqual(['b']);
        expect(grouped.delivered).toEqual([]);
    });
});
