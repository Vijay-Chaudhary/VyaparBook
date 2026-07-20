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
