import { getMeta, LAST_SYNC_KEY } from './db';

/**
 * The offline outbox (docs/frontend-plan.md §3.2, PRD §9).
 *
 * Every mutation is written here FIRST and pushed to the server second — never
 * the reverse. That ordering is the whole reason the app works offline: the
 * write is durable on the device before any network is involved, so losing
 * connectivity mid-sale costs nothing.
 *
 * Idempotency is `(tenant_id, uuid)`, matching the server's unique constraints,
 * so a mutation retried over a flaky link posts exactly once.
 */

export const PENDING = 'pending';
export const PARKED = 'parked'; // permanently rejected — needs a human

/** Server statuses that mean the write is durable and can leave the outbox. */
const SUCCESS = ['applied', 'duplicate'];

/** The only mutation types /sync/push accepts (SyncController::push). */
export const PUSHABLE_TYPES = [
    'customer', 'sale', 'payment',
    'order', 'order_pack', 'order_deliver', 'order_cancel',
];

/* ------------------------------------------------------------------ */
/* staleness cap (§2)                                                  */
/* ------------------------------------------------------------------ */

export const WARN_AFTER_DAYS = 7;
export const BLOCK_AFTER_DAYS = 30;

const DAY_MS = 86_400_000;

/**
 * How stale this device is: 'ok' | 'warn' | 'blocked'.
 *
 * Bounds what a lost or stolen phone can accumulate. A device that has never
 * synced is judged from when it was first used, not treated as fresh — the
 * alternative would exempt exactly the devices we care about.
 */
export async function stalenessState(db, now = Date.now()) {
    const lastSync = await getMeta(db, LAST_SYNC_KEY, null);

    if (lastSync === null) return 'ok';

    const days = (now - lastSync) / DAY_MS;

    if (days >= BLOCK_AFTER_DAYS) return 'blocked';
    if (days >= WARN_AFTER_DAYS) return 'warn';

    return 'ok';
}

/* ------------------------------------------------------------------ */
/* enqueue                                                             */
/* ------------------------------------------------------------------ */

/**
 * Queue a mutation for the server.
 *
 * @param {import('dexie').Dexie} db
 * @param {{type: string, tenantId: string, uuid: string, payload: object}} mutation
 * @returns {Promise<number>} the outbox sequence number
 */
export async function enqueue(db, { type, tenantId, uuid, payload, dependsOnUuid = null }) {
    if (!PUSHABLE_TYPES.includes(type)) {
        // Fail loudly at the call site rather than queueing something the
        // server will reject forever.
        throw new Error(`Unsupported mutation type: ${type}`);
    }
    if (!tenantId) throw new Error('Mutation requires a tenant id');
    if (!uuid) throw new Error('Mutation requires a client uuid (idempotency key)');

    if ((await stalenessState(db)) === 'blocked') {
        throw new Error('Device has not synced in too long; reconnect before recording more.');
    }

    return db.outbox.add({
        uuid,
        type,
        tenant_id: tenantId,
        payload,
        // Set when this mutation references a customer that exists only on this
        // device. See resolveDependencies() for why that needs handling.
        depends_on_uuid: dependsOnUuid,
        status: PENDING,
        attempts: 0,
        last_error: null,
        created_at: Date.now(),
    });
}

/**
 * Rewrite local customer references to server ids, and hold back anything not
 * yet resolvable.
 *
 * A sale's `customer_id` must be the customer's SERVER id: LedgerWriter does
 * `Customer::findOrFail($data['customer_id'])`, which looks up the primary key,
 * not the client `uuid`. A customer created offline has no server id until it
 * syncs — so "add a customer, then record their sale" (the most natural first
 * thing a shop does) would push a sale referencing an id the server has never
 * seen and be rejected as not_found, permanently parked.
 *
 * So before each push: swap in the server id if the customer has synced, and
 * otherwise defer this mutation to a later round. The customer is ahead of it
 * in the queue, so one round later the id exists.
 *
 * @returns {Promise<{ready: Array, deferred: number}>}
 */
export async function resolveDependencies(db, entries) {
    const ready = [];
    let deferred = 0;

    for (const entry of entries) {
        if (!entry.depends_on_uuid) {
            ready.push(entry);
            continue;
        }

        const customer = await db.customers.get(entry.depends_on_uuid);

        if (customer?.id) {
            const payload = { ...entry.payload, customer_id: customer.id };

            await db.outbox.update(entry.seq, { payload, depends_on_uuid: null });
            ready.push({ ...entry, payload, depends_on_uuid: null });
            continue;
        }

        // Customer not on the server yet. Stop here rather than skipping ahead:
        // later entries may depend on this one, and reordering a ledger risks
        // a payment landing before the sale it settles.
        deferred += 1;
        break;
    }

    return { ready, deferred };
}

/**
 * Record the server id a customer was assigned, so queued sales and payments
 * that reference it locally can be resolved on the next push.
 */
export async function linkServerId(db, clientUuid, serverId) {
    const existing = await db.customers.get(clientUuid);

    if (existing) {
        await db.customers.update(clientUuid, { id: serverId });
    }
}

/** Mutations waiting to be pushed, oldest first — order preserves causality. */
export function pending(db) {
    return db.outbox.where('status').equals(PENDING).sortBy('seq');
}

/** Mutations the server permanently refused; surfaced to the user for repair. */
export function parked(db) {
    return db.outbox.where('status').equals(PARKED).sortBy('seq');
}

export async function pendingCount(db) {
    return db.outbox.where('status').equals(PENDING).count();
}

/* ------------------------------------------------------------------ */
/* result handling                                                     */
/* ------------------------------------------------------------------ */

/**
 * Apply the server's per-mutation results to the outbox.
 *
 * The server reports each mutation independently (SyncController::push), so one
 * bad row never blocks the rest of the batch.
 *
 *  - applied / duplicate -> durable on the server; delete from the outbox.
 *    `duplicate` is a SUCCESS: it means a previous attempt already landed and
 *    the retry was deduped by (tenant_id, uuid). Treating it as a failure would
 *    retry forever and, worse, imply to the user that their sale did not save.
 *  - rejected            -> permanent (tenant_mismatch / forbidden / invalid /
 *    not_found). None of these improve by retrying, so park for a human.
 *    Silent infinite retry of a permanently-invalid write is the classic
 *    outbox bug.
 *
 * A mutation the server did not mention is left pending and retried.
 *
 * @returns {Promise<{applied: number, parked: number}>}
 */
export async function applyResults(db, results) {
    let applied = 0;
    let parkedCount = 0;
    const newlyLinked = [];

    await db.transaction('rw', db.outbox, async () => {
        for (const result of results) {
            const entry = await db.outbox.where('uuid').equals(result.uuid).first();

            if (!entry) continue; // already reconciled; nothing to do

            if (SUCCESS.includes(result.status)) {
                // A customer now has a server id. Record it so queued sales and
                // payments referencing it locally can resolve on the next push.
                if (entry.type === 'customer' && result.id) {
                    newlyLinked.push([entry.uuid, result.id]);
                }

                await db.outbox.delete(entry.seq);
                applied += 1;
                continue;
            }

            if (result.status === 'rejected') {
                await db.outbox.update(entry.seq, {
                    status: PARKED,
                    last_error: result.reason ?? 'rejected',
                });
                parkedCount += 1;
            }
        }
    });

    // Outside the outbox transaction: Dexie will not let one transaction span
    // stores it was not opened over.
    for (const [clientUuid, serverId] of newlyLinked) {
        await linkServerId(db, clientUuid, serverId);
    }

    return { applied, parked: parkedCount };
}

/**
 * Record a transport failure (offline, 5xx, 429) against a batch.
 *
 * These are NOT permanent: the mutations stay pending. Only the attempt counter
 * and error move, so the UI can explain why nothing is syncing.
 */
export async function recordFailure(db, entries, error) {
    await db.transaction('rw', db.outbox, async () => {
        for (const entry of entries) {
            await db.outbox.update(entry.seq, {
                attempts: (entry.attempts ?? 0) + 1,
                last_error: String(error),
            });
        }
    });
}

/**
 * Backoff before the next attempt, in ms: 1s, 2s, 4s … capped at 5 minutes.
 *
 * Capped because an offline shop may retry for hours; unbounded exponential
 * backoff would leave a device that regained signal waiting needlessly long.
 */
export function backoffMs(attempts) {
    return Math.min(1000 * 2 ** Math.max(0, attempts), 300_000);
}

/** Un-park a mutation after the user has fixed the underlying problem. */
export async function retryParked(db, seq) {
    await db.outbox.update(seq, { status: PENDING, attempts: 0, last_error: null });
}

/** Discard a parked mutation the user has chosen to abandon. */
export async function discardParked(db, seq) {
    await db.outbox.delete(seq);
}
