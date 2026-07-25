/**
 * What a salesman may do with an order, and how the list is grouped.
 *
 * Pure so it can be tested — the repo has no component-test tooling, so any
 * rule that lives only inside a component cannot be covered at all.
 *
 * These mirror App\Orders\OrderStatus on the server, which is the authority.
 * The client's copy exists so the UI can hide an action the server would
 * refuse, not to decide anything on its own.
 */

import { describeLines } from './lineItems';

const ACTIONS = {
    pending: ['cancel'],
    accepted: ['pack', 'cancel'],
    packed: ['deliver', 'cancel'],
    delivered: [],
    rejected: [],
    cancelled: [],
};

export const ORDER_STATUSES = ['pending', 'accepted', 'packed', 'delivered', 'rejected', 'cancelled'];

/** @returns {string[]} the actions this status permits, newest-first ordering. */
export function actionsFor(status) {
    return ACTIONS[status] ?? [];
}

/** @returns {Record<string, object[]>} every status key present, newest first. */
export function groupByStatus(orders) {
    const grouped = Object.fromEntries(ORDER_STATUSES.map((s) => [s, []]));

    for (const order of orders ?? []) {
        if (grouped[order.status]) grouped[order.status].push(order);
    }

    for (const status of ORDER_STATUSES) {
        grouped[status].sort((a, b) => String(b.order_date).localeCompare(String(a.order_date)));
    }

    return grouped;
}

/**
 * The orders a salesman should see, ready to render.
 *
 * Two sources, both necessary. Synced rows come from Dexie with their lines;
 * an order still in the outbox has never reached the server, so it has no row
 * and no lines — its contents live in the payload. Without that second source
 * a salesman takes an order in a village and watches it vanish until signal
 * returns, which is exactly when they most need to see it.
 *
 * A queued order is marked `pending` and carries no actions: the server has
 * never heard of it, so packing or cancelling it would push a mutation naming
 * an order that does not exist and park.
 */
export function buildOrderList({
    orders = [], orderLines = [], outbox = [], packs = [], products = [], locale = 'en',
} = {}) {
    const linesByOrder = new Map();

    for (const line of orderLines) {
        if (! linesByOrder.has(line.order_id)) linesByOrder.set(line.order_id, []);
        linesByOrder.get(line.order_id).push(line);
    }

    const synced = orders.map((order) => ({
        ...order,
        pending: false,
        items: describeLines(linesByOrder.get(order.id) ?? [], packs, products, locale),
    }));

    const queued = outbox
        .filter((entry) => entry.type === 'order')
        .map((entry) => ({
            uuid: entry.uuid,
            customer_id: entry.payload?.customer_id,
            order_date: entry.payload?.order_date,
            total: entry.payload?.total ?? '0',
            status: 'pending',
            pending: true,
            items: describeLines(entry.payload?.lines, packs, products, locale),
        }));

    return [...queued, ...synced];
}
