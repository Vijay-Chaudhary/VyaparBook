/**
 * Exact quantity arithmetic at scale 3 (kg to the gram).
 *
 * Separate from money.js because the server stores quantities as decimal(12,3)
 * — materials are weighed (0.5 kg, 1.250 kg) — while money is scale 2. Same
 * integer-arithmetic reason though: summing stock movements with JS floats
 * would drift, and on-hand must match the server's bcadd at scale 3 exactly.
 *
 * Works in integer MILLIUNITS (1 kg = 1000). Quantities can be negative: a
 * stored stock movement is signed (an `out` is a negative qty), so on-hand is a
 * plain sum.
 */

const SCALE = 3;
const FACTOR = 1000;

/** Parse a decimal quantity string ("1.5", "-0.250") to integer milliunits. */
export function toMilli(value) {
    if (value === null || value === undefined || value === '') return 0;

    const text = String(value).trim();
    const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(text);

    if (!match) throw new Error(`Not a decimal quantity: ${value}`);

    const [, sign, whole = '0', frac = ''] = match;
    // Truncate beyond 3 places, matching bcmath (which discards, not rounds).
    const milli = Number(`${whole || '0'}${(frac + '000').slice(0, SCALE)}`);

    return sign === '-' ? -milli : milli;
}

/** Format integer milliunits as a decimal string, trailing zeros trimmed but
 *  never leaving a bare "5." — "1500" -> "1.5", "1000" -> "1", "1250" -> "1.25". */
export function toDecimal(milli) {
    const negative = milli < 0;
    const abs = Math.abs(Math.trunc(milli));
    const whole = Math.floor(abs / FACTOR);
    const frac = String(abs % FACTOR).padStart(SCALE, '0').replace(/0+$/, '');

    return `${negative ? '-' : ''}${whole}${frac ? '.' + frac : ''}`;
}

/** Format a quantity with its unit for display: "1.5 kg". */
export function formatQty(milli, unit) {
    return unit ? `${toDecimal(milli)} ${unit}` : toDecimal(milli);
}

export function sumMilli(values) {
    return values.reduce((total, value) => total + value, 0);
}
