/**
 * Registers the service worker that makes the app open with no network.
 *
 * Served from `/sw.js` (the site root) deliberately: a worker's scope cannot be
 * broader than its own path, and one under /build/ could not control /app.
 */
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;

    const register = () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // A failed registration means no offline support, not a broken app.
            // The sync layer already reports offline state on its own.
        });
    };

    /*
     * This module is reached through a dynamic import, which frequently
     * resolves AFTER window 'load' has already fired — and a listener added
     * for an event that has passed never runs. That silently left the worker
     * unregistered, so the app looked fine until the network was actually
     * taken away.
     *
     * Registering is still deferred when the page is genuinely still loading,
     * to keep it off the critical path on a slow phone.
     */
    if (document.readyState === 'complete') {
        register();
    } else {
        window.addEventListener('load', register, { once: true });
    }
}
