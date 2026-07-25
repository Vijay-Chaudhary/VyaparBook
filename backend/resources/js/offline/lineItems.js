/**
 * Turning sale lines into something a human reads.
 *
 * Pure: the Dexie reads live in khata.js, so this can be tested directly — the
 * repo has no component-test tooling, and display logic that only exists inside
 * a component cannot be covered at all.
 */

import { productName } from './catalog';
import { toPaise } from './money';

/**
 * A stored rate is always a valid decimal, but a QUEUED sale carries whatever
 * the form submitted, and toPaise throws on anything its regex rejects. One
 * malformed row must not blank a customer's whole ledger, so an unreadable
 * rate reads as zero.
 */
function safeRatePaise(rate) {
    try {
        return toPaise(rate ?? '0');
    } catch {
        return 0;
    }
}

/**
 * @param lines    rows shaped like sale_lines, or an outbox payload's lines
 * @param packs    cached product_packs
 * @param products cached products
 * @param locale   the reader's language
 */
export function describeLines(lines, packs, products, locale = 'en') {
    const packsById = new Map(packs.map((p) => [p.id, p]));
    const productsById = new Map(products.map((p) => [p.id, p]));

    return (lines ?? []).map((line) => {
        const pack = packsById.get(line.product_pack_id);
        const name = pack ? productName(productsById.get(pack.product_id), locale) : '';
        const qty = Number(line.qty) || 0;
        const ratePaise = safeRatePaise(line.rate);

        return {
            // A line whose pack is no longer cached still shows its money —
            // dropping it would understate what the sale was.
            description: [name, pack?.label].filter(Boolean).join(' '),
            qty,
            ratePaise,
            subtotalPaise: ratePaise * qty,
        };
    });
}
