import { sumMilli, toMilli } from './qty';

/**
 * Stock computed from the cached movements (docs/frontend-plan.md §5).
 *
 * Mirrors App\Services\StockService:
 *   on_hand      = Σ movement.qty   (movements are stored SIGNED, so an `out`
 *                  is already negative and this is a plain sum)
 *   below_reorder = reorder_level set AND on_hand < reorder_level
 *
 * Reads only. Stock has no offline write path — sync/push does not accept it —
 * so unlike the khata there is no pending outbox work to fold in. A manager's
 * device caches these rows from sync/pull; a salesman's never receives them.
 */

/** On-hand for one material, in integer milliunits, from cached movements. */
export async function onHandFor(db, material) {
    const movements = await db.stock_movements
        .filter((m) => m.raw_material_id === material.id)
        .toArray();

    return sumMilli(movements.map((m) => toMilli(m.qty)));
}

/**
 * Every material with its on-hand and a below-reorder flag.
 *
 * Sorted below-reorder first: a stock screen exists to answer "what do I need
 * to buy", so the items that need buying belong at the top.
 */
export async function stockList(db, { includeArchived = false } = {}) {
    const materials = await db.raw_materials.toArray();

    const visible = includeArchived ? materials : materials.filter((m) => !m.archived_at);

    const rows = await Promise.all(
        visible.map(async (material) => {
            const onHandMilli = await onHandFor(db, material);
            const reorderMilli = material.reorder_level === null ? null : toMilli(material.reorder_level);

            return {
                ...material,
                onHandMilli,
                belowReorder: reorderMilli !== null && onHandMilli < reorderMilli,
            };
        })
    );

    return rows.sort((a, b) => {
        if (a.belowReorder !== b.belowReorder) return a.belowReorder ? -1 : 1;

        // Then by name, so the list is stable and scannable.
        return String(a.name).localeCompare(String(b.name));
    });
}

/**
 * One material's movement history, newest first, with a running on-hand.
 * The last (chronologically) running value equals onHandFor().
 */
export async function movementsFor(db, material) {
    const movements = await db.stock_movements
        .filter((m) => m.raw_material_id === material.id)
        .toArray();

    movements.sort((a, b) => {
        const dayA = String(a.movement_date).slice(0, 10);
        const dayB = String(b.movement_date).slice(0, 10);

        if (dayA !== dayB) return dayA < dayB ? -1 : 1;

        return String(a.created_at ?? '') < String(b.created_at ?? '') ? -1 : 1;
    });

    let running = 0;

    return movements.map((movement) => {
        const deltaMilli = toMilli(movement.qty);
        running += deltaMilli;

        return { ...movement, deltaMilli, runningMilli: running };
    });
}
