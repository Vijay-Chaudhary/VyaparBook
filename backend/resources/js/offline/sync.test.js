import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CURSOR_KEY, LAST_SYNC_KEY, closeTenantDb, deleteTenantDb, getMeta, openTenantDb, setMeta } from './db';
import { enqueue, parked, pending } from './outbox';
import { PUSH_BATCH_SIZE, assertSafeToSwitch, sync } from './sync';

const TENANT = '11111111-1111-4111-8111-111111111111';

let db;

const mutation = (uuid) => ({
    type: 'sale',
    tenantId: TENANT,
    uuid,
    payload: { customer_id: 'c1', total: '100.00' },
});

/** Minimal fake of the two sync endpoints, recording call order. */
function fakeServer({ pushResults = () => [], pullBody = {}, pushStatus = 200, pullStatus = 200 } = {}) {
    const calls = [];

    const doFetch = vi.fn(async (url, options = {}) => {
        if (url.startsWith('/api/v1/sync/push')) {
            const body = JSON.parse(options.body);
            calls.push({ endpoint: 'push', mutations: body.mutations });

            if (pushStatus !== 200) {
                return {
                    ok: false,
                    status: pushStatus,
                    headers: { get: () => '30' },
                };
            }

            return {
                ok: true,
                status: 200,
                json: async () => ({ results: pushResults(body.mutations) }),
            };
        }

        calls.push({ endpoint: 'pull', url });

        if (pullStatus !== 200) {
            return { ok: false, status: pullStatus, headers: { get: () => null } };
        }

        return { ok: true, status: 200, json: async () => ({ cursor: 0, ...pullBody }) };
    });

    return { doFetch, calls };
}

const withToken = (doFetch) => ({ fetch: doFetch, tokenProvider: async () => 'test-token' });

beforeEach(async () => {
    closeTenantDb();
    await deleteTenantDb(TENANT);
    db = openTenantDb(TENANT);
});

describe('ordering', () => {
    /**
     * Push must precede pull. Pulling first would apply server state over the
     * top of local work, and the shopkeeper would watch a sale they just
     * recorded disappear and then reappear.
     */
    it('pushes before it pulls', async () => {
        await enqueue(db, mutation('u1'));

        const { doFetch, calls } = fakeServer({
            pushResults: (m) => m.map((x) => ({ uuid: x.uuid, status: 'applied' })),
        });

        await sync(db, withToken(doFetch));

        expect(calls.map((c) => c.endpoint)).toEqual(['push', 'pull']);
    });

    it('still pulls when the outbox is empty', async () => {
        const { doFetch, calls } = fakeServer();

        const result = await sync(db, withToken(doFetch));

        expect(calls.map((c) => c.endpoint)).toEqual(['pull']);
        expect(result.ok).toBe(true);
    });
});

describe('offline behaviour', () => {
    it('does nothing destructive when there is no token', async () => {
        await enqueue(db, mutation('u1'));

        const { doFetch, calls } = fakeServer();
        const result = await sync(db, { fetch: doFetch, tokenProvider: async () => null });

        expect(result.ok).toBe(false);
        expect(result.reason).toBe('no_token');
        // No requests, and crucially the queued sale is untouched.
        expect(calls).toHaveLength(0);
        expect(await pending(db)).toHaveLength(1);
    });

    it('keeps the outbox intact when the network fails mid-push', async () => {
        await enqueue(db, mutation('u1'));

        const doFetch = vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        });

        const result = await sync(db, withToken(doFetch));

        expect(result.ok).toBe(false);
        // A transport failure must never cost the shop a sale.
        expect(await pending(db)).toHaveLength(1);
        expect(await parked(db)).toHaveLength(0);
    });

    it('honours a 429 instead of hammering the limiter', async () => {
        await enqueue(db, mutation('u1'));

        const { doFetch } = fakeServer({ pushStatus: 429 });
        const result = await sync(db, withToken(doFetch));

        expect(result.reason).toMatch(/rate_limited/);
        expect(await pending(db)).toHaveLength(1);
    });

    it('does not mark a sync successful when the pull fails', async () => {
        const { doFetch } = fakeServer({ pullStatus: 500 });

        const result = await sync(db, withToken(doFetch));

        expect(result.ok).toBe(false);
        // last_sync_at drives the staleness cap; advancing it on a failed sync
        // would silently reset the clock on a device that is not syncing.
        expect(await getMeta(db, LAST_SYNC_KEY, null)).toBeNull();
    });

    it('reports work that reached the server even if the pull then failed', async () => {
        await enqueue(db, mutation('u1'));

        const { doFetch } = fakeServer({
            pushResults: (m) => m.map((x) => ({ uuid: x.uuid, status: 'applied' })),
            pullStatus: 500,
        });

        const result = await sync(db, withToken(doFetch));

        // The sale IS on the server; only the refresh failed. Saying otherwise
        // would invite the user to re-enter a sale that already exists.
        expect(result.pushed).toBe(1);
        expect(result.ok).toBe(false);
        expect(await pending(db)).toHaveLength(0);
    });
});

describe('exactly-once', () => {
    /**
     * The property the whole design exists for: a mutation retried over a flaky
     * link must post exactly once.
     */
    it('does not re-send a mutation the server already applied', async () => {
        await enqueue(db, mutation('u1'));

        const first = fakeServer({
            pushResults: (m) => m.map((x) => ({ uuid: x.uuid, status: 'applied' })),
        });
        await sync(db, withToken(first.doFetch));

        // Second sync: nothing left to push.
        const second = fakeServer();
        await sync(db, withToken(second.doFetch));

        expect(second.calls.filter((c) => c.endpoint === 'push')).toHaveLength(0);
    });

    it('clears the outbox when a retry comes back as duplicate', async () => {
        await enqueue(db, mutation('u1'));

        // Simulates: the first attempt landed but the response was lost, so the
        // client retried and the server deduped by (tenant_id, uuid).
        const { doFetch } = fakeServer({
            pushResults: (m) => m.map((x) => ({ uuid: x.uuid, status: 'duplicate' })),
        });

        const result = await sync(db, withToken(doFetch));

        expect(result.pushed).toBe(1);
        expect(await pending(db)).toHaveLength(0);
    });
});

describe('batching', () => {
    it('drains a backlog larger than one batch', async () => {
        for (let i = 0; i < PUSH_BATCH_SIZE + 10; i += 1) {
            await enqueue(db, mutation(`u${i}`));
        }

        const { doFetch, calls } = fakeServer({
            pushResults: (m) => m.map((x) => ({ uuid: x.uuid, status: 'applied' })),
        });

        const result = await sync(db, withToken(doFetch));

        expect(calls.filter((c) => c.endpoint === 'push')).toHaveLength(2);
        expect(result.pushed).toBe(PUSH_BATCH_SIZE + 10);
        expect(await pending(db)).toHaveLength(0);
    });

    it('stops instead of looping when a batch makes no progress', async () => {
        await enqueue(db, mutation('u1'));

        // Server returns 200 but says nothing about the mutation.
        const { doFetch, calls } = fakeServer({ pushResults: () => [] });

        await sync(db, withToken(doFetch));

        // Retrying the identical batch forever would spin the loop and the CPU.
        expect(calls.filter((c) => c.endpoint === 'push')).toHaveLength(1);
        expect(await pending(db)).toHaveLength(1);
    });
});

describe('pull', () => {
    it('stores rows and advances the cursor', async () => {
        const { doFetch } = fakeServer({
            pullBody: {
                cursor: 42,
                customers: [{ uuid: 'c1', id: 'srv-c1', name: 'राम ट्रेडर्स', sync_seq: 42 }],
            },
        });

        const result = await sync(db, withToken(doFetch));

        expect(result.pulled).toBe(1);
        expect(await getMeta(db, CURSOR_KEY, 0)).toBe(42);
        expect((await db.customers.get('c1')).name).toBe('राम ट्रेडर्स');
    });

    it('sends the stored cursor on the next pull', async () => {
        await setMeta(db, CURSOR_KEY, 99);

        const { doFetch, calls } = fakeServer();
        await sync(db, withToken(doFetch));

        expect(calls.find((c) => c.endpoint === 'pull').url).toContain('since=99');
    });

    it('is idempotent: the same delta applied twice does not duplicate rows', async () => {
        const body = {
            pullBody: {
                cursor: 7,
                customers: [{ uuid: 'c1', id: 'srv-c1', name: 'राम', sync_seq: 7 }],
            },
        };

        await sync(db, withToken(fakeServer(body).doFetch));
        await sync(db, withToken(fakeServer(body).doFetch));

        // Keyed on uuid, so a replay overwrites rather than inserting again.
        expect(await db.customers.count()).toBe(1);
    });

    it('does not advance the cursor when the row write fails', async () => {
        await setMeta(db, CURSOR_KEY, 5);

        // A row missing its uuid primary key makes bulkPut throw inside the
        // transaction, which must roll the cursor back with it. If the cursor
        // advanced independently, this delta would never be fetched again and
        // those rows would be silently lost forever.
        const { doFetch } = fakeServer({
            pullBody: { cursor: 50, customers: [{ id: 'no-uuid', name: 'broken' }] },
        });

        const result = await sync(db, withToken(doFetch));

        expect(result.ok).toBe(false);
        expect(await getMeta(db, CURSOR_KEY, 0)).toBe(5);
    });

    it('accepts empty tables for a role the server withholds rows from', async () => {
        // A salesman gets empty stock/production arrays by role, not by error.
        const { doFetch } = fakeServer({
            pullBody: { cursor: 3, customers: [], raw_materials: [], stock_movements: [] },
        });

        const result = await sync(db, withToken(doFetch));

        expect(result.ok).toBe(true);
        expect(result.pulled).toBe(0);
    });
});

describe('tenant switching', () => {
    it('refuses to switch while work is unsynced', async () => {
        await enqueue(db, mutation('u1'));

        // PRD §9: the outbox must be flushed first, or unsynced work is
        // stranded in a cache that is about to be closed.
        await expect(assertSafeToSwitch(db)).rejects.toThrow(/unsynced/);
    });

    it('allows the switch once the outbox is empty', async () => {
        await enqueue(db, mutation('u1'));

        const { doFetch } = fakeServer({
            pushResults: (m) => m.map((x) => ({ uuid: x.uuid, status: 'applied' })),
        });
        await sync(db, withToken(doFetch));

        await expect(assertSafeToSwitch(db)).resolves.toBeUndefined();
    });
});
