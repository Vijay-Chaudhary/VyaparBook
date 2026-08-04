import { beforeEach, describe, expect, it } from 'vitest';
import { closeTenantDb, deleteTenantDb, openTenantDb } from './db';
import { enqueue } from './outbox';
import { khataList, ledgerFor, outstandingFor } from './khata';
import { toDecimal } from './money';

const TENANT = '33333333-3333-4333-8333-333333333333';
let db;

const customer = (over = {}) => ({
    uuid: 'cust-1',
    id: 'srv-cust-1',
    name: 'राम ट्रेडर्स',
    opening_balance: '0.00',
    archived_at: null,
    ...over,
});

const sale = (over = {}) => ({
    uuid: `sale-${Math.random()}`,
    customer_id: 'srv-cust-1',
    sale_date: '2026-07-01',
    created_at: '2026-07-01T10:00:00Z',
    total: '100.00',
    reverses_id: null,
    ...over,
});

const payment = (over = {}) => ({
    uuid: `pay-${Math.random()}`,
    customer_id: 'srv-cust-1',
    payment_date: '2026-07-02',
    created_at: '2026-07-02T10:00:00Z',
    amount: '40.00',
    reverses_id: null,
    ...over,
});

beforeEach(async () => {
    closeTenantDb();
    await deleteTenantDb(TENANT);
    db = openTenantDb(TENANT);
});

describe('outstanding', () => {
    /** Mirrors KhataService: opening + Σ sales − Σ payments. */
    it('is opening plus sales minus payments', async () => {
        const c = customer({ opening_balance: '250.00' });
        await db.customers.put(c);
        await db.sales.bulkPut([sale({ total: '100.00' }), sale({ total: '50.50' })]);
        await db.payments.put(payment({ amount: '40.00' }));

        // 250 + 150.50 − 40
        expect(toDecimal(await outstandingFor(db, c))).toBe('360.50');
    });

    it('lets reversals self-cancel instead of excluding rows', async () => {
        const c = customer();
        await db.customers.put(c);
        await db.sales.bulkPut([
            sale({ uuid: 's1', total: '100.00' }),
            // A reversal is an ordinary row with a negated amount (PRD §9).
            sale({ uuid: 's2', total: '-100.00', reverses_id: 's1' }),
        ]);

        expect(toDecimal(await outstandingFor(db, c))).toBe('0.00');
    });

    it('stays exact across many awkward amounts', async () => {
        const c = customer();
        await db.customers.put(c);
        await db.sales.bulkPut(Array.from({ length: 300 }, () => sale({ total: '0.07' })));

        // Float arithmetic lands at 20.999999999999996 here.
        expect(toDecimal(await outstandingFor(db, c))).toBe('21.00');
    });

    it('counts a customer created offline, before it has a server id', async () => {
        // Local-only: no `id` yet, rows reference the client uuid.
        const c = customer({ id: undefined, uuid: 'local-1' });
        await db.customers.put(c);
        await db.sales.put(sale({ customer_id: 'local-1', total: '75.00' }));

        expect(toDecimal(await outstandingFor(db, c))).toBe('75.00');
    });
});

describe('pending work', () => {
    /**
     * An offline sale must move the balance immediately. If it did not, the
     * shopkeeper records a sale, sees the total unchanged, and concludes the
     * app lost it.
     */
    it('includes queued sales and payments', async () => {
        const c = customer();
        await db.customers.put(c);

        await enqueue(db, {
            type: 'sale',
            tenantId: TENANT,
            uuid: 'q1',
            payload: { customer_id: 'srv-cust-1', sale_date: '2026-07-03', total: '60.00' },
        });
        await enqueue(db, {
            type: 'payment',
            tenantId: TENANT,
            uuid: 'q2',
            payload: { customer_id: 'srv-cust-1', payment_date: '2026-07-03', amount: '10.00' },
        });

        expect(toDecimal(await outstandingFor(db, c))).toBe('50.00');
    });

    it('can be excluded to show only what the server has confirmed', async () => {
        const c = customer();
        await db.customers.put(c);
        await enqueue(db, {
            type: 'sale',
            tenantId: TENANT,
            uuid: 'q1',
            payload: { customer_id: 'srv-cust-1', sale_date: '2026-07-03', total: '60.00' },
        });

        expect(toDecimal(await outstandingFor(db, c, { includePending: false }))).toBe('0.00');
    });

    it('never counts a parked mutation', async () => {
        const c = customer();
        await db.customers.put(c);
        const seq = await enqueue(db, {
            type: 'sale',
            tenantId: TENANT,
            uuid: 'q1',
            payload: { customer_id: 'srv-cust-1', sale_date: '2026-07-03', total: '60.00' },
        });

        await db.outbox.update(seq, { status: 'parked' });

        // Parked means permanently rejected — showing it would be money that
        // will never exist.
        expect(toDecimal(await outstandingFor(db, c))).toBe('0.00');
    });

    it('does not double-count a sale once it comes back from the server', async () => {
        const c = customer();
        await db.customers.put(c);

        await enqueue(db, {
            type: 'sale',
            tenantId: TENANT,
            uuid: 'dup-1',
            payload: { customer_id: 'srv-cust-1', sale_date: '2026-07-03', total: '60.00' },
        });
        // Server confirms and the row arrives via pull; the outbox entry is
        // removed by applyResults, so exactly one of them counts.
        await db.outbox.clear();
        await db.sales.put(sale({ uuid: 'dup-1', total: '60.00' }));

        expect(toDecimal(await outstandingFor(db, c))).toBe('60.00');
    });
});

describe('ledger', () => {
    it('runs a balance in date order that ends at the outstanding', async () => {
        const c = customer({ opening_balance: '100.00' });
        await db.customers.put(c);
        await db.sales.put(sale({ sale_date: '2026-07-01', total: '50.00' }));
        await db.payments.put(payment({ payment_date: '2026-07-03', amount: '30.00' }));

        const ledger = await ledgerFor(db, c);

        expect(ledger.map((e) => toDecimal(e.runningPaise))).toEqual(['150.00', '120.00']);

        // The invariant: the last running value IS the outstanding. If these
        // disagree, one is wrong and neither can be trusted.
        expect(ledger.at(-1).runningPaise).toBe(await outstandingFor(db, c));
    });

    it('marks queued entries as pending rather than hiding them', async () => {
        const c = customer();
        await db.customers.put(c);
        await db.sales.put(sale({ sale_date: '2026-07-01', total: '50.00' }));
        await enqueue(db, {
            type: 'payment',
            tenantId: TENANT,
            uuid: 'q1',
            payload: { customer_id: 'srv-cust-1', payment_date: '2026-07-05', amount: '20.00' },
        });

        const ledger = await ledgerFor(db, c);

        expect(ledger).toHaveLength(2);
        expect(ledger[1].pending).toBe(true);
        expect(toDecimal(ledger[1].runningPaise)).toBe('30.00');
    });

    /**
     * The server sends sale_date as a full timestamp while a pending entry
     * carries a bare date. Comparing the raw strings sorts every pending row to
     * the wrong slot — a real ordering bug caught in a browser, not a unit test.
     */
    it('orders across mixed date formats by the calendar day', async () => {
        const c = customer();
        await db.customers.put(c);

        // Synced sale: full ISO timestamp for the same day, earlier in the day.
        await db.sales.put(
            sale({
                uuid: 'synced',
                sale_date: '2026-07-20T00:00:00.000000Z',
                created_at: '2026-07-20T09:00:00Z',
                total: '10.00',
            })
        );

        // Pending payment: bare date, created later the same day.
        await enqueue(db, {
            type: 'payment',
            tenantId: TENANT,
            uuid: 'pending',
            payload: { customer_id: 'srv-cust-1', payment_date: '2026-07-20', amount: '5.00' },
        });
        // enqueue stamps created_at = Date.now(), which is after the synced row.

        const ledger = await ledgerFor(db, c);

        // Chronological: the synced sale (09:00) precedes the just-made payment,
        // rather than the pending row being dragged to the front.
        expect(ledger.map((e) => e.uuid)).toEqual(['synced', 'pending']);
    });

    it('orders same-day entries by creation time, as the server does', async () => {
        const c = customer();
        await db.customers.put(c);
        await db.sales.put(
            sale({ uuid: 'later', sale_date: '2026-07-01', created_at: '2026-07-01T18:00:00Z', total: '10.00' })
        );
        await db.sales.put(
            sale({ uuid: 'earlier', sale_date: '2026-07-01', created_at: '2026-07-01T09:00:00Z', total: '20.00' })
        );

        expect((await ledgerFor(db, c)).map((e) => e.uuid)).toEqual(['earlier', 'later']);
    });
});

describe('khata list', () => {
    it('sorts by outstanding, highest first', async () => {
        await db.customers.bulkPut([
            customer({ uuid: 'a', id: 'srv-a', name: 'कम बकाया' }),
            customer({ uuid: 'b', id: 'srv-b', name: 'ज़्यादा बकाया' }),
        ]);
        await db.sales.bulkPut([
            sale({ customer_id: 'srv-a', total: '10.00' }),
            sale({ customer_id: 'srv-b', total: '900.00' }),
        ]);

        // The list a shopkeeper actually acts on starts with who owes most.
        expect((await khataList(db)).map((c) => c.name)).toEqual(['ज़्यादा बकाया', 'कम बकाया']);
    });

    it('hides archived customers unless asked', async () => {
        await db.customers.bulkPut([
            customer({ uuid: 'a', id: 'srv-a', name: 'सक्रिय' }),
            customer({ uuid: 'b', id: 'srv-b', name: 'पुराना', archived_at: '2026-07-01T00:00:00Z' }),
        ]);

        expect((await khataList(db)).map((c) => c.name)).toEqual(['सक्रिय']);
        expect((await khataList(db, { includeArchived: true }))).toHaveLength(2);
    });
});

describe('ledger line items', () => {
    /** The catalog every item test needs: one product in one pack size. */
    const seedCatalog = async () => {
        await db.products.bulkPut([{ id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' }]);
        await db.product_packs.bulkPut([{ id: 'k1', product_id: 'p1', label: '1kg' }]);
    };

    it('lists the items of a synced sale at the price charged', async () => {
        await db.customers.put(customer());
        await seedCatalog();
        await db.sales.put(sale({ uuid: 'sale-1', id: 'srv-sale-1', total: '210.00' }));
        await db.sale_lines.bulkPut([
            { id: 'l1', sale_id: 'srv-sale-1', product_pack_id: 'k1', qty: 2, rate: '105.00' },
        ]);

        const entries = await ledgerFor(db, customer());
        const saleEntry = entries.find((e) => e.uuid === 'sale-1');

        expect(saleEntry.items).toHaveLength(1);
        expect(saleEntry.items[0].description).toBe('Sev Mix 1kg');
        expect(saleEntry.items[0].subtotalPaise).toBe(21000);
    });

    it('reports the negotiated rate, not the pack default', async () => {
        await db.customers.put(customer());
        await seedCatalog();
        await db.sales.put(sale({ uuid: 'sale-2', id: 'srv-sale-2', total: '160.00' }));
        await db.sale_lines.bulkPut([
            { id: 'l2', sale_id: 'srv-sale-2', product_pack_id: 'k1', qty: 2, rate: '80.00' },
        ]);

        const entries = await ledgerFor(db, customer());

        expect(entries.find((e) => e.uuid === 'sale-2').items[0].ratePaise).toBe(8000);
    });

    it('lists the items of a queued sale from its outbox payload', async () => {
        await db.customers.put(customer());
        await seedCatalog();
        await enqueue(db, {
            type: 'sale',
            tenantId: TENANT,
            uuid: 'queued-1',
            payload: {
                customer_id: 'srv-cust-1',
                sale_date: '2026-07-02',
                total: '105.00',
                lines: [{ product_pack_id: 'k1', qty: 1, rate: '105.00' }],
            },
        });

        const entries = await ledgerFor(db, customer());
        const queued = entries.find((e) => e.uuid === 'queued-1');

        // A pending sale is shown, so it must carry its items too — otherwise it
        // renders as a bare total with nothing under it.
        expect(queued.pending).toBe(true);
        expect(queued.items[0].description).toBe('Sev Mix 1kg');
    });

    it('gives a payment an empty item list so the entry shape is uniform', async () => {
        await db.customers.put(customer());
        await seedCatalog();
        await db.payments.put(payment({ uuid: 'pay-1' }));

        const entries = await ledgerFor(db, customer());

        expect(entries.find((e) => e.uuid === 'pay-1').items).toEqual([]);
    });
});

describe('the order behind a sale', () => {
    it('points a delivered order\'s sale back at the order', async () => {
        // The khata is where a wrong quantity gets noticed, so it has to know
        // which sale came from an order and which was a counter sale.
        const c = customer();
        await db.customers.add(c);
        await db.sales.add(sale({ uuid: 'from-order', id: 'srv-sale-1' }));
        await db.sales.add(sale({ uuid: 'counter', id: 'srv-sale-2' }));
        await db.orders.add({
            uuid: 'ord-1', id: 'srv-order-1', customer_id: 'srv-cust-1',
            status: 'delivered', sale_id: 'srv-sale-1', order_date: '2026-07-01', sync_seq: 1,
        });

        const byUuid = Object.fromEntries(
            (await ledgerFor(db, c)).map((e) => [e.uuid, e.orderId])
        );

        expect(byUuid['from-order']).toBe('srv-order-1');
        expect(byUuid.counter).toBeNull();
    });

    it('leaves a sale unlinked when the order points nowhere', async () => {
        // A cancelled order has its sale_id cleared, so the reversed sale must
        // not keep offering a route to an order that no longer owns it.
        const c = customer();
        await db.customers.add(c);
        await db.sales.add(sale({ uuid: 'voided', id: 'srv-sale-9' }));
        await db.orders.add({
            uuid: 'ord-9', id: 'srv-order-9', customer_id: 'srv-cust-1',
            status: 'cancelled', sale_id: null, order_date: '2026-07-01', sync_seq: 2,
        });

        const [entry] = await ledgerFor(db, c);

        expect(entry.orderId).toBeNull();
    });
});
