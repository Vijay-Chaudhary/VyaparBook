/**
 * Reading the cached catalog for display.
 *
 * Pack rows in Dexie carry `product_id` but no product NAME — the server nests
 * packs under their product (CatalogController) and sync flattens them, so the
 * names stay in the `products` store. Anything showing a pack to a human has to
 * make that join itself, which is what this module is for.
 */

/** A name that is null, empty or only spaces is not a name. */
function usable(value) {
    const trimmed = typeof value === 'string' ? value.trim() : '';

    return trimmed === '' ? null : trimmed;
}

/**
 * A product's display name in the reader's language.
 *
 * Falls back to the other language rather than showing nothing: `name_en` is
 * nullable in the schema while `name_hi` is NOT NULL, so a shop that never
 * filled in English names must still get a readable dropdown.
 *
 * Returns '' for an unknown product so a missing lookup renders as absence
 * rather than the string "undefined".
 */
export function productName(product, locale = 'en') {
    if (!product) return '';

    const hi = usable(product.name_hi);
    const en = usable(product.name_en);

    return (locale === 'hi' ? hi || en : en || hi) ?? '';
}
