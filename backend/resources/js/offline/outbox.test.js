import { beforeEach, describe, expect, it } from 'vitest';
import { LAST_SYNC_KEY, closeTenantDb, deleteTenantDb, openTenantDb, setMeta } from './db';
import {
    BLOCK_AFTER_DAYS,
    PARKED,
    PENDING,
    WARN_AFTER_DAYS,
    applyResults,
    backoffMs,
    discardParked,
    enqueue,
    parked,
    pending,
    recordFailure,
    retryParked,
    stalenessState,
} from './outbox';

const TENANT = '11111111-1111-4111-8111-111111111111';
const OTHER_TENANT = '22222222-2222-4222-8222-222222222222';

let db;

const mutation = (uuid, overrides = {}) => ({
    type: 'sale',
    tenantId: TENANT,
    uuid,
    payload: { customer_id: 'c1', total: '100.00' },
    ...overrides,
});

beforeEach(async () => {
    closeTenantDb();
    await deleteTenantDb(TENANT);
    await deleteTenantDb(OTHER_TENANT);
    db = openTenantDb(TENANT);
});

describe('tenant isolation', () => {
    it('gives each tenant a physically separate database', async () => {
        await enqueue(db, mutation('u1'));

        // Switching tenants must not surface the previous tenant's queue: the
        // stores are different IndexedDB databases, not filtered tables.
        const other = openTenantDb(OTHER_TENANT);
        expect(await pending(other)).toHaveLength(0);

        const back = openTenantDb(TENANT);
        expect(await pending(back)).toHaveLength(1);
    });
});

describe('enqueue', () => {
    it('queues a mutation as pending with its idempotency key', async () => {
        await enqueue(db, mutation('u1'));

        const [entry] = await pending(db);
        expect(entry.uuid).toBe('u1');
        expect(entry.status).toBe(PENDING);
        expect(entry.tenant_id).toBe(TENANT);
        expect(entry.attempts).toBe(0);
    });

    it('preserves insertion order so causally-related writes stay ordered', async () => {
        await enqueue(db, mutation('u1', { type: 'customer' }));
        await enqueue(db, mutation('u2', { type: 'sale' }));
        await enqueue(db, mutation('u3', { type: 'payment' }));

        // A payment must not reach the server before the sale it settles.
        expect((await pending(db)).map((e) => e.uuid)).toEqual(['u1', 'u2', 'u3']);
    });

    it('refuses a type the server cannot accept', async () => {
        // /sync/push only handles customer|sale|payment. Queueing anything else
        // would sit in the outbox being rejected forever.
        await expect(enqueue(db, mutation('u1', { type: 'stock_movement' }))).rejects.toThrow(
            /Unsupported mutation type/
        );
        expect(await pending(db)).toHaveLength(0);
    });

    it('refuses a mutation with no idempotency key', async () => {
        // Without a uuid the server cannot dedupe, so a retry would double-post
        // a sale. Better to fail at the call site.
        await expect(enqueue(db, mutation(undefined))).rejects.toThrow(/uuid/);
    });

    it('refuses a mutation with no tenant', async () => {
        await expect(enqueue(db, mutation('u1', { tenantId: null }))).rejects.toThrow(/tenant/);
    });
});

describe('applying server results', () => {
    it('removes mutations the server applied', async () => {
        await enqueue(db, mutation('u1'));

        const outcome = await applyResults(db, [{ uuid: 'u1', status: 'applied', id: 'srv-1' }]);

        expect(outcome).toEqual({ applied: 1, parked: 0 });
        expect(await pending(db)).toHaveLength(0);
    });

    /**
     * The single most dangerous case in the whole outbox. `duplicate` means a
     * previous attempt already landed and this retry was deduped by
     * (tenant_id, uuid). Treating it as failure would retry forever AND tell
     * the shopkeeper their sale did not save when it did.
     */
    it('treats a duplicate as success, because the write already landed', async () => {
        await enqueue(db, mutation('u1'));

        const outcome = await applyResults(db, [{ uuid: 'u1', status: 'duplicate', id: 'srv-1' }]);

        expect(outcome.applied).toBe(1);
        expect(await pending(db)).toHaveLength(0);
        expect(await parked(db)).toHaveLength(0);
    });

    it.each(['tenant_mismatch', 'forbidden', 'invalid', 'not_found'])(
        'parks a permanently rejected mutation (%s) instead of retrying it',
        async (reason) => {
            await enqueue(db, mutation('u1'));

            const outcome = await applyResults(db, [{ uuid: 'u1', status: 'rejected', reason }]);

            expect(outcome).toEqual({ applied: 0, parked: 1 });
            // Must leave the pending queue — none of these improve on retry.
            expect(await pending(db)).toHaveLength(0);

            const [entry] = await parked(db);
            expect(entry.status).toBe(PARKED);
            expect(entry.last_error).toBe(reason);
        }
    );

    it('leaves a mutation the server did not mention pending', async () => {
        await enqueue(db, mutation('u1'));
        await enqueue(db, mutation('u2'));

        await applyResults(db, [{ uuid: 'u1', status: 'applied' }]);

        // Silence is not consent: u2 is retried, never assumed applied.
        expect((await pending(db)).map((e) => e.uuid)).toEqual(['u2']);
    });

    it('reconciles a mixed batch independently per mutation', async () => {
        await enqueue(db, mutation('ok'));
        await enqueue(db, mutation('dupe'));
        await enqueue(db, mutation('bad'));
        await enqueue(db, mutation('quiet'));

        const outcome = await applyResults(db, [
            { uuid: 'ok', status: 'applied' },
            { uuid: 'dupe', status: 'duplicate' },
            { uuid: 'bad', status: 'rejected', reason: 'invalid' },
        ]);

        // One bad row must never block the good ones around it.
        expect(outcome).toEqual({ applied: 2, parked: 1 });
        expect((await pending(db)).map((e) => e.uuid)).toEqual(['quiet']);
        expect((await parked(db)).map((e) => e.uuid)).toEqual(['bad']);
    });

    it('ignores a result for a mutation already reconciled', async () => {
        await enqueue(db, mutation('u1'));
        await applyResults(db, [{ uuid: 'u1', status: 'applied' }]);

        // A replayed response must not throw or resurrect anything.
        const outcome = await applyResults(db, [{ uuid: 'u1', status: 'applied' }]);
        expect(outcome).toEqual({ applied: 0, parked: 0 });
    });
});

describe('transport failures', () => {
    it('keeps mutations pending and counts the attempt', async () => {
        await enqueue(db, mutation('u1'));
        const batch = await pending(db);

        await recordFailure(db, batch, new Error('offline'));

        // A network error is NOT the server's verdict — nothing may be dropped.
        const [entry] = await pending(db);
        expect(entry.status).toBe(PENDING);
        expect(entry.attempts).toBe(1);
        expect(entry.last_error).toMatch(/offline/);
    });

    it('backs off exponentially and caps at five minutes', async () => {
        expect(backoffMs(0)).toBe(1000);
        expect(backoffMs(1)).toBe(2000);
        expect(backoffMs(2)).toBe(4000);
        // Capped: an offline shop may retry for hours, and a device that
        // regains signal should not sit waiting on an unbounded delay.
        expect(backoffMs(50)).toBe(300_000);
    });
});

describe('parked recovery', () => {
    it('can retry a parked mutation once the problem is fixed', async () => {
        await enqueue(db, mutation('u1'));
        await applyResults(db, [{ uuid: 'u1', status: 'rejected', reason: 'invalid' }]);

        const [entry] = await parked(db);
        await retryParked(db, entry.seq);

        expect(await parked(db)).toHaveLength(0);
        const [again] = await pending(db);
        expect(again.attempts).toBe(0);
        expect(again.last_error).toBeNull();
    });

    it('can discard a parked mutation the user abandons', async () => {
        await enqueue(db, mutation('u1'));
        await applyResults(db, [{ uuid: 'u1', status: 'rejected', reason: 'invalid' }]);

        const [entry] = await parked(db);
        await discardParked(db, entry.seq);

        expect(await parked(db)).toHaveLength(0);
    });
});

describe('staleness cap', () => {
    const daysAgo = (n) => Date.now() - n * 86_400_000;

    it('is ok for a device that has never synced', async () => {
        // A brand new device has nothing to be stale about.
        expect(await stalenessState(db)).toBe('ok');
    });

    it('warns after the warn threshold', async () => {
        await setMeta(db, LAST_SYNC_KEY, daysAgo(WARN_AFTER_DAYS + 1));
        expect(await stalenessState(db)).toBe('warn');
    });

    it('still accepts writes while only warning', async () => {
        await setMeta(db, LAST_SYNC_KEY, daysAgo(WARN_AFTER_DAYS + 1));

        // Warning must never interrupt a shop that is merely offline.
        await expect(enqueue(db, mutation('u1'))).resolves.toBeDefined();
    });

    it('blocks new writes past the block threshold', async () => {
        await setMeta(db, LAST_SYNC_KEY, daysAgo(BLOCK_AFTER_DAYS + 1));

        expect(await stalenessState(db)).toBe('blocked');
        await expect(enqueue(db, mutation('u1'))).rejects.toThrow(/not synced in too long/);
    });

    it('never discards already-queued work when blocking', async () => {
        await enqueue(db, mutation('u1'));
        await setMeta(db, LAST_SYNC_KEY, daysAgo(BLOCK_AFTER_DAYS + 1));

        await expect(enqueue(db, mutation('u2'))).rejects.toThrow();

        // Blocking stops NEW entries accruing; it must not touch the existing
        // queue, which still syncs on reconnect.
        expect(await pending(db)).toHaveLength(1);
    });
});
