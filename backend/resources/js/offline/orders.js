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
