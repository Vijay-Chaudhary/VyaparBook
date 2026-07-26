import { useEffect, useState } from 'react';

/**
 * A minimal path router for the /app shell.
 *
 * Hand-rolled rather than react-router (~10KB gz) because the vendor chunk is
 * already 55% over budget and this app has five routes with one parameter
 * between them. If routing grows nested layouts or loaders, revisit.
 *
 * Uses the History API so URLs are real and shareable — the service worker
 * resolves any /app/* deep link to the same cached shell, and this reads the
 * path from there.
 */

export const BASE = '/app';

/** Current path relative to /app, e.g. '/khata/abc'. */
function currentPath() {
    const path = window.location.pathname.slice(BASE.length);

    return path === '' ? '/' : path;
}

export function navigate(to) {
    window.history.pushState({}, '', BASE + (to === '/' ? '' : to));
    // pushState does not emit an event; tell our own listeners.
    window.dispatchEvent(new PopStateEvent('popstate'));
}

/**
 * Where a customer's name should take you, or null if it cannot take you
 * anywhere.
 *
 * Every screen that lists customers routes through here so the destination is
 * spelled once. Null rather than a best-effort path: a row the cache cannot
 * resolve must render as plain text, because /khata/undefined opens an empty
 * ledger that reads as lost data.
 *
 * Encoded to match the decodeURIComponent in matchRoute().
 */
export function customerPath(customer) {
    if (! customer?.uuid) return null;

    return `/khata/${encodeURIComponent(customer.uuid)}`;
}

export function useRoute() {
    const [path, setPath] = useState(currentPath);

    useEffect(() => {
        const onChange = () => setPath(currentPath());

        // Covers both the browser Back button and navigate().
        window.addEventListener('popstate', onChange);

        return () => window.removeEventListener('popstate', onChange);
    }, []);

    return path;
}

/**
 * Match a path against patterns with `:param` segments.
 *
 * @returns {{name: string, params: object}} the first match, or the fallback
 */
export function matchRoute(path, routes, fallback = 'home') {
    const segments = path.split('/').filter(Boolean);

    for (const [pattern, name] of Object.entries(routes)) {
        const patternSegments = pattern.split('/').filter(Boolean);

        if (patternSegments.length !== segments.length) continue;

        const params = {};
        let matched = true;

        for (const [i, part] of patternSegments.entries()) {
            if (part.startsWith(':')) {
                params[part.slice(1)] = decodeURIComponent(segments[i]);
            } else if (part !== segments[i]) {
                matched = false;
                break;
            }
        }

        if (matched) return { name, params };
    }

    return { name: fallback, params: {} };
}
