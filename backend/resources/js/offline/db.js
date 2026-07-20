import Dexie from 'dexie';

/**
 * Per-tenant local cache (docs/frontend-plan.md §3.1, PRD §9).
 *
 * The database NAME carries the tenant id, so two businesses can never share a
 * store. This is structural isolation, not a filter someone can forget: there
 * is no query that could return another tenant's rows, because those rows live
 * in a different IndexedDB database entirely.
 *
 * Mirrors the sync/pull payload. Stock and production tables exist here even
 * though only owner/admin receive those rows — the server sends empty arrays to
 * a salesman by role, and an empty table is the correct representation of that.
 */

/** Only one tenant's database is open at a time (PRD §9: no cross-tenant mixing). */
let current = null;
let currentTenantId = null;

export function databaseName(tenantId) {
    return `vyaparbook_t_${tenantId}`;
}

/**
 * Open (or return) the cache for a tenant.
 *
 * Switching tenants closes the previous database first. Callers must have
 * flushed the outbox before switching — see assertSafeToSwitch().
 */
export function openTenantDb(tenantId) {
    if (!tenantId) throw new Error('openTenantDb requires a tenant id');

    if (current && currentTenantId === tenantId) return current;

    if (current) {
        current.close();
        current = null;
        currentTenantId = null;
    }

    const db = new Dexie(databaseName(tenantId));

    db.version(1).stores({
        // `uuid` is the client-generated natural key and the idempotency key the
        // server dedupes on; `id` is the server's. Rows pulled from the server
        // have both, rows created offline have only uuid until they sync.
        customers: 'uuid, id, name, archived_at, sync_seq',
        sales: 'uuid, id, customer_id, sale_date, sync_seq',
        sale_lines: 'uuid, id, sale_id, sync_seq',
        payments: 'uuid, id, customer_id, payment_date, sync_seq',
        raw_materials: 'uuid, id, name, sync_seq',
        stock_movements: 'uuid, id, raw_material_id, sync_seq',
        production_batches: 'uuid, id, sync_seq',
        material_consumptions: 'uuid, id, production_batch_id, sync_seq',

        // Single-row-per-key store: sync cursor, last successful sync time.
        meta: 'key',

        // The outbox. `status` is indexed because draining queries by it.
        outbox: '++seq, uuid, status, created_at',
    });

    current = db;
    currentTenantId = tenantId;

    return db;
}

export function currentDb() {
    return current;
}

/** Close the open database — used when switching tenants or signing out. */
export function closeTenantDb() {
    if (current) {
        current.close();
        current = null;
        currentTenantId = null;
    }
}

/**
 * Permanently remove a tenant's local cache.
 *
 * Called on sign-out and after a tenant switch. Leaving a shop's khata on a
 * shared phone after the user has left it is a data-exposure problem, not a
 * housekeeping one.
 */
export async function deleteTenantDb(tenantId) {
    if (currentTenantId === tenantId) closeTenantDb();

    await Dexie.delete(databaseName(tenantId));
}

/* ------------------------------------------------------------------ */
/* meta helpers                                                        */
/* ------------------------------------------------------------------ */

export async function getMeta(db, key, fallback = null) {
    const row = await db.meta.get(key);

    return row === undefined ? fallback : row.value;
}

export async function setMeta(db, key, value) {
    await db.meta.put({ key, value });
}

/** Sync cursor: the highest sync_seq this device has seen. */
export const CURSOR_KEY = 'cursor';

/** Epoch ms of the last fully successful sync — drives the staleness cap. */
export const LAST_SYNC_KEY = 'last_sync_at';
