/**
 * Exact rupee arithmetic.
 *
 * The server computes balances with bcadd/bcsub at scale 2 precisely because
 * money must not drift. JavaScript numbers are binary floats — 0.1 + 0.2 is
 * 0.30000000000000004 — so doing the same sums with `+` would give a khata that
 * slowly disagrees with the server, in a ledger a shopkeeper reconciles by hand.
 *
 * Everything here works in integer PAISE and only formats at the edges. No
 * decimal library: integers are exact, and this is a few lines against ~6KB.
 */

/**
 * Parse a decimal rupee string ("250.50") to integer paise (25050).
 *
 * Accepts what the API emits: plain decimal strings, optionally negative.
 *
 * @param {string|number|null|undefined} value
 * @returns {number} paise
 */
export function toPaise(value) {
    if (value === null || value === undefined || value === '') return 0;

    const text = String(value).trim();
    const match = /^(-?)(\d*)(?:\.(\d*))?$/.exec(text);

    if (!match) throw new Error(`Not a decimal amount: ${value}`);

    const [, sign, whole = '0', frac = ''] = match;

    // Pad/truncate to exactly 2 places. Truncation (not rounding) matches the
    // server: bcmath discards beyond scale rather than rounding up.
    const paise = Number(`${whole || '0'}${(frac + '00').slice(0, 2)}`);

    return sign === '-' ? -paise : paise;
}

/**
 * Format integer paise as a plain decimal string ("25050" -> "250.50").
 * This is the wire format — always 2 decimal places, no separators.
 */
export function toDecimal(paise) {
    const negative = paise < 0;
    const abs = Math.abs(Math.trunc(paise));
    const whole = Math.floor(abs / 100);
    const frac = String(abs % 100).padStart(2, '0');

    return `${negative ? '-' : ''}${whole}.${frac}`;
}

/**
 * Format paise for display with Indian digit grouping: ₹1,20,000.50.
 *
 * Not ₹120,000.50 — Indian grouping is 2,2,3 after the first three digits, and
 * getting it wrong makes the number read as a different amount to the person
 * whose money it is.
 */
export function formatRupees(paise, { withSymbol = true } = {}) {
    const negative = paise < 0;
    const abs = Math.abs(Math.trunc(paise));

    const formatted = new Intl.NumberFormat('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(abs / 100);

    return `${negative ? '−' : ''}${withSymbol ? '₹' : ''}${formatted}`;
}

/** Sum a list of paise values. */
export function sumPaise(values) {
    return values.reduce((total, value) => total + value, 0);
}
