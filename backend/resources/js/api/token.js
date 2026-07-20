/**
 * JWT lifecycle for the React layer (docs/frontend-plan.md §2).
 *
 * The token is held in a module-scoped variable and NOWHERE else — not
 * localStorage, not sessionStorage, not a readable cookie. Any of those is
 * exfiltratable by a single XSS; a variable dies with the page, and the
 * session cookie can always mint a fresh one.
 *
 * Consequence, by design: a page reload costs one /auth/token round trip.
 * That is the price of not leaving a bearer token on disk.
 */

let token = null;
let expiresAt = null; // epoch ms
let inFlight = null; // de-dupes concurrent refreshes

/** Refresh a minute early, so a request never races its own expiry. */
const EXPIRY_MARGIN_MS = 60_000;

function isFresh() {
    return token !== null && expiresAt !== null && Date.now() < expiresAt - EXPIRY_MARGIN_MS;
}

/**
 * Current JWT, fetching one if missing or near expiry.
 *
 * Concurrent callers share a single in-flight request: on app boot several
 * components ask at once, and without this each would mint a separate token.
 *
 * @returns {Promise<string|null>} null when offline or the session has ended —
 *   callers must treat that as "cannot sync right now", never as "log out".
 *   Local data entry continues regardless; that is the whole point of the
 *   outbox.
 */
export async function getToken() {
    if (isFresh()) return token;
    if (inFlight) return inFlight;

    inFlight = fetch('/auth/token', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin', // send the session cookie
    })
        .then(async (response) => {
            if (!response.ok) {
                // 401 = session gone; 429 = throttled. Both mean "no token now".
                // Neither justifies destroying unsynced local work.
                token = null;
                expiresAt = null;
                return null;
            }

            const data = await response.json();
            token = data.token;
            expiresAt = Date.now() + data.expires_in_minutes * 60_000;

            return token;
        })
        .catch(() => {
            // Offline. Expected, not exceptional — stay quiet and let the
            // caller queue work locally.
            return null;
        })
        .finally(() => {
            inFlight = null;
        });

    return inFlight;
}

/** Drop the cached token, e.g. after a 401 from the API. */
export function clearToken() {
    token = null;
    expiresAt = null;
}
