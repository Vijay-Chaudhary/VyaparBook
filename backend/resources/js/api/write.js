import { getToken } from './token';

/**
 * Authenticated write to the JSON API for the ONLINE-ONLY surfaces (stock,
 * production — docs/frontend-plan.md §5).
 *
 * These are not offline: sync/push accepts only customer/sale/payment, and
 * stock is a manager's back-office task, not something done at the counter mid-
 * outage. So a write here talks to the server directly and reports its verdict,
 * rather than queueing.
 *
 * The three outcomes a stock screen must distinguish, kept as structured fields
 * so the UI never has to parse a message:
 *   - ok:            applied
 *   - status 402:    plan gate — the feature needs an upgrade, not a retry
 *   - status 403:    role — managers only (shouldn't happen; the tab is hidden)
 *   - offline/5xx:   transient — tell them to try again when connected
 */
export async function apiWrite(path, body) {
    const token = await getToken();

    if (!token) {
        // No token → offline or session gone. A stock write cannot proceed;
        // this is a clean "you're offline" for the caller, not an error.
        return { ok: false, offline: true };
    }

    let response;

    try {
        response = await fetch(`/api/v1${path}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                Authorization: `Bearer ${token}`,
            },
            body: JSON.stringify(body),
        });
    } catch {
        return { ok: false, offline: true };
    }

    if (response.ok) {
        return { ok: true, status: response.status, data: await response.json() };
    }

    // 402 carries an upgrade prompt; 422 carries field errors. Surface both.
    const payload = await response.json().catch(() => ({}));

    return {
        ok: false,
        status: response.status,
        upgrade: response.status === 402,
        forbidden: response.status === 403,
        message: payload.message ?? null,
        errors: payload.errors ?? null,
    };
}
