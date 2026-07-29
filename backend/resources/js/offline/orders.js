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

/**
 * The statuses that still need someone to do something. A delivered order has
 * moved into the customer's khata; a cancelled or rejected one needs nothing
 * further. Keeping them here would grow the list without bound and bury the
 * work that is actually outstanding.
 */
export const OPEN_STATUSES = ['pending', 'accepted', 'packed'];

/** Days an order may stay undelivered before it needs chasing. */
export const OVERDUE_DAYS = 3;

/**
 * Is this order late?
 *
 * Counted from the day it was TAKEN, not from approval: that is the delay the
 * shop actually experiences, and it includes any time the owner sat on the
 * decision. Only open orders can be late — a delivered one arrived, and a
 * cancelled one is not coming.
 *
 * Day 3 is still within three days; late starts on day 4.
 */
export function isOverdue(order, now = new Date()) {
    if (! order || ! OPEN_STATUSES.includes(order.status)) return false;

    // Explicitly, because new Date(null) is the epoch rather than an invalid
    // date — a dateless order would otherwise read as decades late.
    if (! order.order_date) return false;

    const taken = new Date(order.order_date);

    if (Number.isNaN(taken.getTime())) return false;

    const days = Math.floor((now.getTime() - taken.getTime()) / 86400000);

    return days > OVERDUE_DAYS;
}

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
    userId = null,
} = {}) {
    const linesByOrder = new Map();

    for (const line of orderLines) {
        if (! linesByOrder.has(line.order_id)) linesByOrder.set(line.order_id, []);
        linesByOrder.get(line.order_id).push(line);
    }

    const synced = orders.map((order) => ({
        ...order,
        pending: false,
        // Everyone in the shop now sees every order, because anyone may have to
        // deliver one. Picking up a colleague's delivery should be deliberate,
        // so the list has to say which orders are not yours.
        //
        // Unknown userId means unmarked rather than "someone else's": before
        // whoami resolves, claiming every order belongs to a stranger would be
        // wrong about all of them.
        mine: userId == null ? true : Number(order.created_by) === Number(userId),
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
            // It is in THIS device's outbox, so this device took it.
            mine: true,
            items: describeLines(entry.payload?.lines, packs, products, locale),
        }));

    return [...queued, ...synced];
}
