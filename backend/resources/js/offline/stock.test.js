import { beforeEach, describe, expect, it } from 'vitest';
import { closeTenantDb, deleteTenantDb, openTenantDb } from './db';
import { movementsFor, onHandFor, stockList } from './stock';
import { toDecimal } from './qty';

const TENANT = '44444444-4444-4444-8444-444444444444';
let db;

// raw_materials is keyed on `uuid` too; server rows always carry it.
const material = (over = {}) => ({
    uuid: `${over.id ?? 'mat-1'}-uuid`,
    id: 'mat-1',
    name: 'Besan',
    unit: 'kg',
    reorder_level: null,
    archived_at: null,
    ...over,
});

// Movements are stored SIGNED, as the server keeps them (out = negative).
// The stock_movements store is keyed on `uuid` (server rows carry both uuid and
// id), so every fixture needs one.
let movementSeq = 0;
const movement = (over = {}) => ({
    uuid: `mv-uuid-${movementSeq++}`,
    id: `mv-${movementSeq}`,
    raw_material_id: 'mat-1',
    movement_date: '2026-07-01',
    created_at: '2026-07-01T10:00:00Z',
    kind: 'in',
    qty: '10.000',
    ...over,
});

beforeEach(async () => {
    closeTenantDb();
    await deleteTenantDb(TENANT);
    db = openTenantDb(TENANT);
});

describe('on hand', () => {
    it('sums signed movements', async () => {
        const m = material();
        await db.raw_materials.put(m);
        await db.stock_movements.bulkPut([
            movement({ kind: 'in', qty: '10.000' }),
            movement({ kind: 'out', qty: '-3.500' }),
            movement({ kind: 'adjust', qty: '0.250' }),
        ]);

        // 10 − 3.5 + 0.25 = 6.75
        expect(toDecimal(await onHandFor(db, m))).toBe('6.75');
    });

    it('is exact across many small weights', async () => {
        const m = material();
        await db.raw_materials.put(m);
        await db.stock_movements.bulkPut(
            Array.from({ length: 300 }, () => movement({ qty: '0.001' }))
        );

        // Floats land at 0.30000000000000004; on-hand must be 0.3.
        expect(toDecimal(await onHandFor(db, m))).toBe('0.3');
    });
});

describe('stock list', () => {
    it('flags a material below its reorder level', async () => {
        await db.raw_materials.put(material({ id: 'mat-1', reorder_level: '5.000' }));
        await db.stock_movements.put(movement({ raw_material_id: 'mat-1', qty: '2.000' }));

        const [row] = await stockList(db);
        expect(row.belowReorder).toBe(true);
    });

    it('does not flag a material with no reorder level set', async () => {
        await db.raw_materials.put(material({ id: 'mat-1', reorder_level: null }));
        await db.stock_movements.put(movement({ raw_material_id: 'mat-1', qty: '0.000' }));

        const [row] = await stockList(db);
        // No threshold means nothing to be below, even at zero on-hand.
        expect(row.belowReorder).toBe(false);
    });

    it('puts below-reorder materials first', async () => {
        await db.raw_materials.bulkPut([
            material({ id: 'ok', name: 'Plenty', reorder_level: '1.000' }),
            material({ id: 'low', name: 'Running out', reorder_level: '10.000' }),
        ]);
        await db.stock_movements.bulkPut([
            movement({ id: 'a', raw_material_id: 'ok', qty: '50.000' }),
            movement({ id: 'b', raw_material_id: 'low', qty: '2.000' }),
        ]);

        // What needs buying belongs at the top.
        expect((await stockList(db)).map((m) => m.id)).toEqual(['low', 'ok']);
    });

    it('hides archived materials unless asked', async () => {
        await db.raw_materials.bulkPut([
            material({ id: 'a', name: 'Active' }),
            material({ id: 'b', name: 'Gone', archived_at: '2026-07-01T00:00:00Z' }),
        ]);

        expect((await stockList(db)).map((m) => m.name)).toEqual(['Active']);
        expect(await stockList(db, { includeArchived: true })).toHaveLength(2);
    });
});

describe('movement history', () => {
    it('runs a balance in date order ending at on-hand', async () => {
        const m = material();
        await db.raw_materials.put(m);
        await db.stock_movements.bulkPut([
            movement({ movement_date: '2026-07-01', kind: 'in', qty: '10.000' }),
            movement({ movement_date: '2026-07-03', kind: 'out', qty: '-4.000' }),
        ]);

        const history = await movementsFor(db, m);

        expect(history.map((e) => toDecimal(e.runningMilli))).toEqual(['10', '6']);
        expect(history.at(-1).runningMilli).toBe(await onHandFor(db, m));
    });
});
