import { getToken } from '../api/token';
import { CURSOR_KEY, LAST_SYNC_KEY, getMeta, setMeta } from './db';
import { applyResults, pending, recordFailure, resolveDependencies } from './outbox';

/**
 * The sync engine (docs/frontend-plan.md §3.3).
 *
 * Push before pull, always: local work must reach the server before remote
 * state is applied over the top of the view, or a shopkeeper watches their
 * just-recorded sale vanish and reappear.
 *
 * Every failure path here is non-destructive. Nothing in the outbox is ever
 * dropped because of a network error — only an explicit server rejection can
 * remove or park a mutation. The failure mode this guards against is silent
 * loss from a shop's ledger, which is the worst thing this product can do.
 */

/** Tables in the pull payload, keyed by their Dexie store name. */
const PULL_TABLES = [
    'customers',
    'sales',
    'sale_lines',
    'payments',
    'raw_materials',
    'stock_movements',
    'production_batches',
    'material_consumptions',
    // Read-only server data: pulled like the rest, never pushed.
    'beats',
    'beat_customers',
];

/** One batch per push. Bounded so a long-offline device does not send a huge body. */
export const PUSH_BATCH_SIZE = 50;

export class SyncResult {
    constructor({ pushed = 0, parked = 0, pulled = 0, cursor = null, reason = null } = {}) {
        this.pushed = pushed;
        this.parked = parked;
        this.pulled = pulled;
        this.cursor = cursor;
        this.reason = reason; // set when the sync could not run
    }

    get ok() {
        return this.reason === null;
    }
}

/**
 * Run one full sync cycle.
 *
 * @param {import('dexie').Dexie} db
 * @param {{fetch?: typeof fetch, tokenProvider?: () => Promise<string|null>}} deps
 *   Injected so the state machine is testable without a browser or a server.
 */
export async function sync(db, deps = {}) {
    const doFetch = deps.fetch ?? globalThis.fetch;
    const tokenProvider = deps.tokenProvider ?? getToken;

    const token = await tokenProvider();

    // No token means no network or no session. Expected, not exceptional: the
    // outbox keeps accumulating and we try again later.
    if (!token) return new SyncResult({ reason: 'no_token' });

    let pushed = 0;
    let parkedTotal = 0;

    try {
        const result = await push(db, doFetch, token);
        pushed = result.pushed;
        parkedTotal = result.parked;
    } catch (error) {
        return new SyncResult({ reason: `push_failed: ${error.message}` });
    }

    let pulled = 0;
    let cursor = null;

    try {
        const result = await pull(db, doFetch, token);
        pulled = result.pulled;
        cursor = result.cursor;
    } catch (error) {
        // Push already succeeded, so report it — the work IS on the server even
        // though the view is not yet refreshed.
        return new SyncResult({ pushed, parked: parkedTotal, reason: `pull_failed: ${error.message}` });
    }

    // Best-effort: a stale catalog still lets the shop work, so a failure here
    // must not fail the sync that already moved the ledger.
    try {
        await refreshCatalog(db, doFetch, token);
    } catch {
        /* keep the previous catalog */
    }

    await setMeta(db, LAST_SYNC_KEY, Date.now());

    return new SyncResult({ pushed, parked: parkedTotal, pulled, cursor });
}

/**
 * Drain the outbox in batches until nothing pending remains.
 *
 * Stops if a batch makes no progress (nothing applied, nothing parked), so a
 * server that keeps ignoring a mutation cannot spin this loop forever.
 */
async function push(db, doFetch, token) {
    let pushed = 0;
    let parkedTotal = 0;

    for (;;) {
        const queued = (await pending(db)).slice(0, PUSH_BATCH_SIZE);

        if (queued.length === 0) break;

        // Swap local customer references for server ids, holding back anything
        // whose customer has not synced yet (see resolveDependencies).
        const { ready: batch } = await resolveDependencies(db, queued);

        // Everything is waiting on a customer that is itself still queued —
        // which can only happen if that customer failed to apply. Stop rather
        // than spin.
        if (batch.length === 0) break;

        const body = {
            mutations: batch.map((entry) => ({
                type: entry.type,
                tenant_id: entry.tenant_id,
                uuid: entry.uuid,
                payload: entry.payload,
            })),
        };

        let response;

        try {
            response = await doFetch('/api/v1/sync/push', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${token}`,
                },
                body: JSON.stringify(body),
            });
        } catch (error) {
            // Transport failure: keep everything pending and stop.
            await recordFailure(db, batch, error);
            throw new Error('network');
        }

        if (!response.ok) {
            await recordFailure(db, batch, `HTTP ${response.status}`);

            // 429 carries Retry-After; honour it rather than hammering a limiter
            // that is already telling us to stop.
            if (response.status === 429) {
                throw new Error(`rate_limited:${response.headers.get('Retry-After') ?? ''}`);
            }

            throw new Error(`HTTP ${response.status}`);
        }

        const { results = [] } = await response.json();
        const outcome = await applyResults(db, results);

        pushed += outcome.applied;
        parkedTotal += outcome.parked;

        // No progress: every mutation in the batch is still pending. Retrying
        // the identical batch would loop forever.
        if (outcome.applied === 0 && outcome.parked === 0) break;
    }

    return { pushed, parked: parkedTotal };
}

/** Delta pull since the stored cursor, applied idempotently by uuid. */
async function pull(db, doFetch, token) {
    const since = await getMeta(db, CURSOR_KEY, 0);

    const response = await doFetch(`/api/v1/sync/pull?since=${encodeURIComponent(since)}`, {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const payload = await response.json();
    let pulled = 0;

    await db.transaction('rw', ...PULL_TABLES.map((t) => db[t]), db.meta, async () => {
        for (const table of PULL_TABLES) {
            const rows = payload[table] ?? [];

            if (rows.length === 0) continue;

            // bulkPut, keyed on uuid: the server's copy is authoritative, and a
            // row we already hold is overwritten rather than duplicated. This is
            // also what makes a repeated pull harmless.
            await db[table].bulkPut(rows);
            pulled += rows.length;
        }

        // Advance the cursor only inside the same transaction as the rows. If
        // this were separate and the write failed, the device would skip a
        // delta permanently and silently lose rows it never fetched again.
        await db.meta.put({ key: CURSOR_KEY, value: payload.cursor ?? since });
    });

    return { pulled, cursor: payload.cursor ?? since };
}

/**
 * Replace the cached catalog from GET /catalog.
 *
 * Wholesale replacement, not a delta: the catalog is small and rarely changes,
 * and the endpoint has no cursor. Replacing outright also means an archived
 * product genuinely disappears locally rather than lingering because no
 * tombstone was sent.
 */
async function refreshCatalog(db, doFetch, token) {
    const response = await doFetch('/api/v1/catalog', {
        headers: { Accept: 'application/json', Authorization: `Bearer ${token}` },
    });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const { products = [] } = await response.json();

    // Flatten the nested packs into their own store so sale entry can look one
    // up by id without walking every product.
    const packs = products.flatMap((product) =>
        (product.packs ?? []).map((pack) => ({ ...pack, product_id: product.id }))
    );

    await db.transaction('rw', db.products, db.product_packs, async () => {
        await db.products.clear();
        await db.product_packs.clear();
        await db.products.bulkPut(products.map(({ packs: _ignored, ...rest }) => rest));
        await db.product_packs.bulkPut(packs);
    });
}

/**
 * Whether the tenant may be switched (PRD §9): the outbox must be empty first,
 * or unsynced work would be stranded in a cache about to be closed.
 */
export async function assertSafeToSwitch(db) {
    const outstanding = await pending(db);

    if (outstanding.length > 0) {
        throw new Error(
            `${outstanding.length} unsynced change(s) — sync before switching business.`
        );
    }
}
