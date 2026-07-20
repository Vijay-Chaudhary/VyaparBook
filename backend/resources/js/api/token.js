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

/*
 * The business the token should be scoped to, for a user who belongs to more
 * than one. Persisted (localStorage) so the choice survives a reload — it is
 * only a business id the user is a member of, never a credential, so it is safe
 * on disk. A single-business user leaves this null and is auto-scoped.
 */
const ACTIVE_KEY = 'vb_active_business';
let activeBusinessId = readActiveBusiness();

function readActiveBusiness() {
    try {
        return localStorage.getItem(ACTIVE_KEY) || null;
    } catch {
        return null; // storage disabled (private mode) — degrade to "must pick each load"
    }
}

export function getActiveBusiness() {
    return activeBusinessId;
}

/** Record the chosen business and drop the cached token so the next getToken
 *  re-scopes to it. */
export function setActiveBusiness(id) {
    activeBusinessId = id;
    token = null;
    expiresAt = null;

    try {
        if (id) localStorage.setItem(ACTIVE_KEY, id);
        else localStorage.removeItem(ACTIVE_KEY);
    } catch {
        /* non-persistent is acceptable; the in-memory value still works this session */
    }
}

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

    // Scope to the chosen business if one is set. The server verifies membership
    // and 403s a business the user does not belong to.
    const url = activeBusinessId
        ? `/auth/token?business=${encodeURIComponent(activeBusinessId)}`
        : '/auth/token';

    inFlight = fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin', // send the session cookie
    })
        .then(async (response) => {
            if (response.status === 403) {
                // The stored business is no longer ours (access revoked, or a
                // stale localStorage id). Clear it so the next load re-picks.
                setActiveBusiness(null);
                return null;
            }

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
