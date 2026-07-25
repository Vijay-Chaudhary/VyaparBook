import { describe, expect, it } from 'vitest';
import { actionsFor, buildOrderList, groupByStatus } from './orders';

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
