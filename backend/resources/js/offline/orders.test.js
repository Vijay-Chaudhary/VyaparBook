import { describe, expect, it } from 'vitest';
import { actionsFor, buildOrderList, groupByStatus, isOverdue } from './orders';

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

describe('buildOrderList', () => {
    const packs = [{ id: 'k1', product_id: 'p1', label: '1kg' }];
    const products = [{ id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' }];

    it('attaches a synced order its line items', () => {
        const [order] = buildOrderList({
            orders: [{ id: 'o1', uuid: 'u1', status: 'accepted', total: '210.00' }],
            orderLines: [{ id: 'l1', order_id: 'o1', product_pack_id: 'k1', qty: 2, rate: '105.00' }],
            packs, products, locale: 'en',
        });

        expect(order.items).toHaveLength(1);
        expect(order.items[0].description).toBe('Sev Mix 1kg');
        expect(order.items[0].qty).toBe(2);
        expect(order.items[0].subtotalPaise).toBe(21000);
        expect(order.pending).toBe(false);
    });

    it('shows an order still in the outbox, with its items from the payload', () => {
        // Otherwise a salesman takes an order in a village and it vanishes
        // until signal returns — exactly when they most need to see it.
        const [order] = buildOrderList({
            outbox: [{
                type: 'order', uuid: 'q1',
                payload: {
                    customer_id: 'c1', order_date: '2026-07-26', total: '105.00',
                    lines: [{ product_pack_id: 'k1', qty: 1, rate: '105.00' }],
                },
            }],
            packs, products, locale: 'en',
        });

        expect(order.pending).toBe(true);
        expect(order.status).toBe('pending');
        expect(order.items[0].description).toBe('Sev Mix 1kg');
    });

    it("gives an approver's queued order the status the server will grant it", () => {
        // The owner is the one who approves; showing their own order as
        // "Waiting" left them waiting for themselves until the next pull.
        const [order] = buildOrderList({
            outbox: [{
                type: 'order', uuid: 'q1',
                payload: { customer_id: 'c1', order_date: '2026-08-04', total: '105.00', lines: [] },
            }],
            packs, products, role: 'owner',
        });

        expect(order.status).toBe('accepted');
        // Still unsynced, which is what suppresses its actions.
        expect(order.pending).toBe(true);
    });

    it('does the same for an admin, who may also approve', () => {
        const [order] = buildOrderList({
            outbox: [{ type: 'order', uuid: 'q1', payload: { lines: [] } }],
            packs, products, role: 'admin',
        });

        expect(order.status).toBe('accepted');
    });

    it("leaves a salesman's queued order waiting, because it genuinely is", () => {
        const [order] = buildOrderList({
            outbox: [{ type: 'order', uuid: 'q1', payload: { lines: [] } }],
            packs, products, role: 'salesman',
        });

        expect(order.status).toBe('pending');
    });

    it('treats an unresolved role as not an approver', () => {
        // whoami has not answered yet. Promising an acceptance the server may
        // refuse is the worse of the two guesses.
        const [order] = buildOrderList({
            outbox: [{ type: 'order', uuid: 'q1', payload: { lines: [] } }],
            packs, products, role: null,
        });

        expect(order.status).toBe('pending');
    });

    it('ignores outbox entries that are not orders', () => {
        const list = buildOrderList({
            outbox: [
                { type: 'payment', uuid: 'p1', payload: { amount: '50.00' } },
                { type: 'order_pack', uuid: 'p2', payload: { order_uuid: 'u1' } },
            ],
            packs, products,
        });

        expect(list).toEqual([]);
    });

    it('lists queued orders before synced ones, since they are the newest', () => {
        const list = buildOrderList({
            orders: [{ id: 'o1', uuid: 'u1', status: 'accepted', total: '10.00' }],
            outbox: [{ type: 'order', uuid: 'q1', payload: { lines: [], total: '20.00' } }],
            packs, products,
        });

        expect(list.map((o) => o.uuid)).toEqual(['q1', 'u1']);
    });

    it('gives an order with no lines an empty item list rather than failing', () => {
        const [order] = buildOrderList({
            orders: [{ id: 'o1', uuid: 'u1', status: 'delivered', total: '0.00' }],
            packs, products,
        });

        expect(order.items).toEqual([]);
    });
});

describe('isOverdue', () => {
    const now = new Date('2026-07-30T10:00:00Z');
    const open = (over = {}) => ({ status: 'accepted', order_date: '2026-07-26', ...over });

    it('is not late on the third day — three days is still within three days', () => {
        expect(isOverdue(open({ order_date: '2026-07-27' }), now)).toBe(false);
    });

    it('is late on the fourth day', () => {
        expect(isOverdue(open({ order_date: '2026-07-26' }), now)).toBe(true);
    });

    it('is not late when it was taken today', () => {
        expect(isOverdue(open({ order_date: '2026-07-30' }), now)).toBe(false);
    });

    it('counts from when it was taken, so time spent awaiting approval still counts', () => {
        // Sat unapproved for a week: the shop has been waiting regardless.
        expect(isOverdue(open({ status: 'pending', order_date: '2026-07-20' }), now)).toBe(true);
    });

    it('is never late once it is finished, however it finished', () => {
        for (const status of ['delivered', 'cancelled', 'rejected']) {
            expect(isOverdue(open({ status, order_date: '2026-07-01' }), now)).toBe(false);
        }
    });

    it('handles the full timestamp a synced row carries', () => {
        expect(isOverdue(open({ order_date: '2026-07-26T00:00:00.000000Z' }), now)).toBe(true);
    });

    it('says no rather than throwing on a missing or unreadable date', () => {
        expect(isOverdue(open({ order_date: null }), now)).toBe(false);
        expect(isOverdue(open({ order_date: 'not a date' }), now)).toBe(false);
        expect(isOverdue(undefined, now)).toBe(false);
    });
});

describe('buildOrderList — whose order is it', () => {
    const packs = [{ id: 'k1', product_id: 'p1', label: '1kg' }];
    const products = [{ id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' }];

    it('marks an order this device took as mine', () => {
        const [order] = buildOrderList({
            orders: [{ id: 'o1', uuid: 'u1', status: 'accepted', created_by: 7 }],
            packs, products, userId: 7,
        });

        expect(order.mine).toBe(true);
    });

    it('marks a colleague\'s order as not mine, so delivering it is deliberate', () => {
        const [order] = buildOrderList({
            orders: [{ id: 'o1', uuid: 'u1', status: 'packed', created_by: 9 }],
            packs, products, userId: 7,
        });

        expect(order.mine).toBe(false);
    });

    it('compares ids across types, since JSON and Dexie disagree about numbers', () => {
        const [order] = buildOrderList({
            orders: [{ id: 'o1', uuid: 'u1', status: 'packed', created_by: '7' }],
            packs, products, userId: 7,
        });

        expect(order.mine).toBe(true);
    });

    it('claims nothing before whoami has resolved', () => {
        // Marking every order as a stranger's while userId is still null would
        // be wrong about all of them, including the ones this device took.
        const [order] = buildOrderList({
            orders: [{ id: 'o1', uuid: 'u1', status: 'packed', created_by: 9 }],
            packs, products,
        });

        expect(order.mine).toBe(true);
    });

    it('treats a queued order as mine — it is in this device\'s outbox', () => {
        const [order] = buildOrderList({
            outbox: [{ type: 'order', uuid: 'q1', payload: { customer_id: 'c1', lines: [] } }],
            packs, products, userId: 7,
        });

        expect(order.mine).toBe(true);
    });
});
