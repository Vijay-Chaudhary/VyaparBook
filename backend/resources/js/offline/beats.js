/**
 * Today's beat, read from the offline cache (PRD Phase 3).
 *
 * Beats are server-written only, so this module never writes — it answers
 * "who am I calling on today?" from whatever the last successful pull left
 * behind, which is the whole point: a salesman in a village with no signal
 * still gets their list.
 */

import { currentDb } from './db';

/** ISO weekday, 1 = Monday … 7 = Sunday, matching the server's `weekdays`. */
export function isoWeekday(date = new Date()) {
    return date.getDay() === 0 ? 7 : date.getDay();
}

/**
 * Beats that run on `date` for `userId`, each with its customers in call order.
 *
 * Unassigned beats are included: in a small shop the owner often works a route
 * themselves, and hiding an unassigned beat would leave that day looking empty.
 */
export async function beatsForDay(userId, date = new Date()) {
    const db = currentDb();
    if (!db) return [];

    const day = isoWeekday(date);

    const beats = (await db.beats.toArray())
        .filter((beat) => !beat.archived_at)
        .filter((beat) => Array.isArray(beat.weekdays) && beat.weekdays.includes(day))
        .filter((beat) => beat.assigned_user_id == null || beat.assigned_user_id === userId)
        .sort((a, b) => (a.name || '').localeCompare(b.name || ''));

    if (beats.length === 0) return [];

    const beatIds = new Set(beats.map((b) => b.id));
    const links = (await db.beat_customers.toArray())
        .filter((link) => beatIds.has(link.beat_id))
        .sort((a, b) => (a.position ?? 0) - (b.position ?? 0));

    // Customers come from the same cache the khata screen uses, so a beat never
    // shows a name the rest of the app cannot open.
    const customers = await db.customers.toArray();
    const byId = new Map(customers.map((c) => [c.id, c]));

    return beats.map((beat) => ({
        ...beat,
        customers: links
            .filter((link) => link.beat_id === beat.id)
            .map((link) => byId.get(link.customer_id))
            .filter(Boolean),
    }));
}
