import { getLocale } from '../i18n';
import { describeLines } from './lineItems';
import { sumPaise, toPaise } from './money';
import { PENDING } from './outbox';

/**
 * The khata, computed locally from the cache (docs/frontend-plan.md §3).
 *
 * Mirrors App\Services\KhataService exactly:
 *   outstanding = opening_balance + Σ sale.total − Σ payment.amount
 *
 * Reversals are ordinary rows with negated amounts, so they self-cancel and no
 * row is ever excluded or mutated — outstanding stays recomputable (PRD §9).
 * All arithmetic is in integer paise; see money.js for why.
 *
 * This has to agree with the server to the paisa. A shopkeeper who sees one
 * figure on the phone and another on a printed statement stops trusting both.
 */

/**
 * Outstanding for one customer, from cached rows.
 *
 * `includePending` folds in outbox work that has not reached the server yet.
 * That is what makes an offline sale appear in the balance immediately —
 * without it the shopkeeper records a sale and the total does not move, which
 * reads as the app having lost it.
 */
export async function outstandingFor(db, customer, { includePending = true } = {}) {
    const customerKeys = [customer.uuid, customer.id].filter(Boolean);

    const sales = await db.sales.filter((s) => customerKeys.includes(s.customer_id)).toArray();
    const payments = await db.payments.filter((p) => customerKeys.includes(p.customer_id)).toArray();

    let total = toPaise(customer.opening_balance);
    total += sumPaise(sales.map((s) => toPaise(s.total)));
    total -= sumPaise(payments.map((p) => toPaise(p.amount)));

    if (includePending) {
        const queued = await pendingFor(db, customerKeys);
        total += queued.salesPaise;
        total -= queued.paymentsPaise;
    }

    return total;
}

/**
 * Outbox entries for this customer that the server has not yet accepted.
 *
 * Only PENDING counts. A parked mutation was permanently rejected, so folding
 * it into the balance would show money that will never exist.
 */
async function pendingFor(db, customerKeys) {
    const entries = await db.outbox.where('status').equals(PENDING).toArray();
    const mine = entries.filter((e) => customerKeys.includes(e.payload?.customer_id));

    return {
        salesPaise: sumPaise(
            mine.filter((e) => e.type === 'sale').map((e) => toPaise(e.payload.total ?? '0'))
        ),
        paymentsPaise: sumPaise(
            mine.filter((e) => e.type === 'payment').map((e) => toPaise(e.payload.amount ?? '0'))
        ),
    };
}

/**
 * Every customer with their outstanding — the "who owes me" screen.
 * Archived customers are hidden unless asked for, matching GET /khata.
 */
export async function khataList(db, { includeArchived = false } = {}) {
    const customers = await db.customers.toArray();

    const visible = includeArchived ? customers : customers.filter((c) => !c.archived_at);

    const rows = await Promise.all(
        visible.map(async (customer) => ({
            ...customer,
            outstandingPaise: await outstandingFor(db, customer),
        }))
    );

    // Highest dues first: that is the list a shopkeeper actually acts on.
    return rows.sort((a, b) => b.outstandingPaise - a.outstandingPaise);
}

/**
 * One customer's statement: sales and payments as a single time-ordered stream
 * with a running balance, exactly as KhataService::ledgerFor builds it.
 *
 * The last running value equals outstandingFor() — if those two ever disagree,
 * one of them is wrong and the ledger cannot be trusted.
 */
export async function ledgerFor(db, customer, { includePending = true } = {}) {
    const customerKeys = [customer.uuid, customer.id].filter(Boolean);

    const sales = await db.sales.filter((s) => customerKeys.includes(s.customer_id)).toArray();
    const payments = await db.payments.filter((p) => customerKeys.includes(p.customer_id)).toArray();

    // Read the catalog once for the whole ledger rather than per sale. A synced
    // sale's items come from cached sale_lines; a queued one's from its outbox
    // payload, which is why both sources are resolved through the same formatter.
    const packs = await db.product_packs.toArray();
    const products = await db.products.toArray();
    const allLines = await db.sale_lines.toArray();
    const locale = getLocale();

    const entries = [
        ...sales.map((s) => ({
            kind: s.reverses_id ? 'sale_reversal' : 'sale',
            uuid: s.uuid,
            date: s.sale_date,
            created_at: s.created_at,
            deltaPaise: toPaise(s.total),
            pending: false,
            items: describeLines(
                allLines.filter((l) => l.sale_id === s.id),
                packs, products, locale
            ),
        })),
        ...payments.map((p) => ({
            kind: p.reverses_id ? 'payment_reversal' : 'payment',
            uuid: p.uuid,
            date: p.payment_date,
            created_at: p.created_at,
            deltaPaise: -toPaise(p.amount),
            pending: false,
            items: [],   // a payment has no lines; keep the entry shape uniform
        })),
    ];

    if (includePending) {
        const queued = (await db.outbox.where('status').equals(PENDING).toArray()).filter((e) =>
            customerKeys.includes(e.payload?.customer_id)
        );

        for (const entry of queued) {
            if (entry.type === 'sale') {
                entries.push({
                    kind: 'sale',
                    uuid: entry.uuid,
                    date: entry.payload.sale_date,
                    created_at: new Date(entry.created_at).toISOString(),
                    deltaPaise: toPaise(entry.payload.total ?? '0'),
                    pending: true, // shown as "not yet synced", never hidden
                    items: describeLines(entry.payload.lines, packs, products, locale),
                });
            } else if (entry.type === 'payment') {
                entries.push({
                    kind: 'payment',
                    uuid: entry.uuid,
                    date: entry.payload.payment_date,
                    created_at: new Date(entry.created_at).toISOString(),
                    deltaPaise: -toPaise(entry.payload.amount ?? '0'),
                    pending: true,
                    items: [],
                });
            }
        }
    }

    entries.sort((a, b) => {
        // Compare the calendar day only. The server sends sale_date as a full
        // timestamp ("2026-07-20T00:00:00Z") while a pending entry carries a
        // bare "2026-07-20"; a raw string compare treats those as different
        // days and shoves every pending row to the wrong slot. Both start with
        // YYYY-MM-DD, so slice to that.
        const dayA = String(a.date).slice(0, 10);
        const dayB = String(b.date).slice(0, 10);

        if (dayA !== dayB) return dayA < dayB ? -1 : 1;

        // Same day: fall back to creation order, as the server does.
        return String(a.created_at ?? '') < String(b.created_at ?? '') ? -1 : 1;
    });

    let running = toPaise(customer.opening_balance);

    return entries.map((entry) => {
        running += entry.deltaPaise;

        return { ...entry, runningPaise: running };
    });
}
