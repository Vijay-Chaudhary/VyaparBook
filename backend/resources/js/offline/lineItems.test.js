import { describe, expect, it } from 'vitest';
import { describeLines } from './lineItems';

const packs = [{ id: 'k1', product_id: 'p1', label: '1kg' }];
const products = [{ id: 'p1', name_en: 'Sev Mix', name_hi: 'सेव मिक्स' }];

describe('describeLines', () => {
    it('describes a line with its product, size, qty and the rate charged', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 3, rate: '105.00' }], packs, products, 'en'
        );

        expect(item.description).toBe('Sev Mix 1kg');
        expect(item.qty).toBe(3);
        expect(item.ratePaise).toBe(10500);
        expect(item.subtotalPaise).toBe(31500);
    });

    it('uses the reader\'s language for the product name', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 1, rate: '105.00' }], packs, products, 'hi'
        );

        expect(item.description).toBe('सेव मिक्स 1kg');
    });

    it('shows the negotiated rate, not the pack default', () => {
        // The whole point of the feature: the ledger must report what was
        // actually charged, even when that is nothing like today's list price.
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 2, rate: '80.00' }], packs, products, 'en'
        );

        expect(item.ratePaise).toBe(8000);
        expect(item.subtotalPaise).toBe(16000);
    });

    it('keeps a return line negative so a void reads as real', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: -2, rate: '105.00' }], packs, products, 'en'
        );

        expect(item.qty).toBe(-2);
        expect(item.subtotalPaise).toBe(-21000);
    });

    it('still renders a line whose pack is not cached, rather than dropping it', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'gone', qty: 1, rate: '10.00' }], packs, products, 'en'
        );

        // Dropping it would understate the sale; an unnamed line is honest.
        expect(item.description).toBe('');
        expect(item.subtotalPaise).toBe(1000);
    });

    it('survives a line with no rate at all rather than throwing', () => {
        // A sale written before this feature has no rate on its outbox payload;
        // toPaise throws on undefined, and a ledger that crashes is worse than
        // one that shows a zero.
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 1 }], packs, products, 'en'
        );

        expect(item.ratePaise).toBe(0);
    });

    it('returns an empty list for a sale with no lines', () => {
        expect(describeLines(undefined, packs, products, 'en')).toEqual([]);
    });
});

describe('describeLines — what acceptance changed', () => {
    it('reports what was ordered when the owner cut the quantity', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 8, rate: '90.00', ordered_qty: 10, ordered_rate: '90.00' }],
            packs, products, 'en'
        );

        // Both halves, even though only the qty moved: "was 10 × ₹90" is the
        // promise the salesman made, and half of it is not a promise.
        expect(item.originalQty).toBe(10);
        expect(item.originalRatePaise).toBe(9000);
    });

    it('reports what was ordered when the owner raised the rate', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 10, rate: '95.00', ordered_qty: 10, ordered_rate: '90.00' }],
            packs, products, 'en'
        );

        expect(item.originalQty).toBe(10);
        expect(item.originalRatePaise).toBe(9000);
    });

    it('says nothing about a line acceptance left alone', () => {
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 10, rate: '90.00', ordered_qty: 10, ordered_rate: '90.00' }],
            packs, products, 'en'
        );

        // Absent, not null: the screen tests `!== undefined`, so a common
        // unedited line must add no noise to the row at all.
        expect(item.originalQty).toBeUndefined();
        expect(item.originalRatePaise).toBeUndefined();
    });

    it('says nothing when the original is unknown, rather than guessing', () => {
        // An order taken before the audit trail existed. Unknown must read the
        // same as unchanged — the UI may never claim a comparison it could not
        // make.
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 8, rate: '95.00' }], packs, products, 'en'
        );

        expect(item.originalQty).toBeUndefined();
    });

    it('leaves sale lines untouched, which never carry originals', () => {
        // describeLines is shared with the khata ledger. A sale is what was
        // sold; an order's negotiation history is not a khata entry.
        const [item] = describeLines(
            [{ product_pack_id: 'k1', qty: 2, rate: '105.00', list_rate: '110.00' }],
            packs, products, 'en'
        );

        expect(item).not.toHaveProperty('originalQty');
        expect(item).not.toHaveProperty('originalRatePaise');
    });
});
