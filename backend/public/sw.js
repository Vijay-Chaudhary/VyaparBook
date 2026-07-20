/**
 * VyaparBook service worker (docs/frontend-plan.md §1, PRD §9/§15).
 *
 * Its one job is to make the app *open* with no network. The DATA comes from
 * IndexedDB via Dexie, never from here — so this caches the shell, the built
 * assets and the fonts, and deliberately caches nothing else.
 *
 * Two things are never cached, for correctness rather than tidiness:
 *
 *  - /api/*      — the local source of truth is Dexie. A cached API response
 *                  would be a second, staler copy of the same data racing it,
 *                  and the sync engine could not tell which it received.
 *  - /auth/token — a bearer credential. Writing it into a cache the page can
 *                  read would undo the whole point of keeping the JWT in
 *                  memory.
 */

const VERSION = 'v1';
const SHELL_CACHE = `vyaparbook-shell-${VERSION}`;
const ASSET_CACHE = `vyaparbook-assets-${VERSION}`;

/** The single document every /app/* route resolves to. */
const SHELL_URL = '/app';

const PRECACHE = [
    SHELL_URL,
    '/fonts/noto-devanagari-deva.woff2',
    '/fonts/noto-devanagari-latin.woff2',
    '/fonts/inter-latin.woff2',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL_CACHE)
            // addAll is atomic — one failure discards the batch, so a partially
            // cached shell can never be served.
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => ![SHELL_CACHE, ASSET_CACHE].includes(key))
                        .map((key) => caches.delete(key))
                )
            )
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    // Only ever handle our own origin's GETs.
    if (request.method !== 'GET' || url.origin !== self.location.origin) return;

    // Never intercept the API or the token endpoint — see the header comment.
    if (url.pathname.startsWith('/api/') || url.pathname === '/auth/token') return;

    // Navigations: serve the cached shell when the network is unavailable.
    // Network-first so a deployed change is picked up as soon as there is
    // signal, rather than being pinned to a stale shell.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (url.pathname.startsWith('/app')) {
                        const copy = response.clone();
                        caches.open(SHELL_CACHE).then((cache) => cache.put(SHELL_URL, copy));
                    }

                    return response;
                })
                .catch(async () => {
                    // Offline. Any /app/* deep link resolves to the one shell;
                    // React reads the URL and routes from there.
                    const cached = await caches.match(SHELL_URL);

                    return cached ?? Response.error();
                })
        );

        return;
    }

    // Built assets and fonts are content-hashed or stable, so cache-first is
    // safe and saves a round trip on every load.
    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/fonts/')) {
        event.respondWith(
            caches.match(request).then(
                (cached) =>
                    cached ??
                    fetch(request).then((response) => {
                        // Only cache a genuine success: caching an opaque or
                        // error response would pin a broken asset until the
                        // next version bump.
                        if (response.ok) {
                            const copy = response.clone();
                            caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));
                        }

                        return response;
                    })
            )
        );
    }
});
