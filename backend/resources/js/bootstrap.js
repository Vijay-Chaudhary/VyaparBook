/**
 * Loaded on every page, Blade and React alike — so it must stay tiny.
 *
 * Laravel's default bootstrap imports axios (~18KB gzipped) and hangs it off
 * `window`. Nothing in this app uses it: `fetch` is in every browser we target
 * and costs nothing. That 18KB was being paid on the *login screen* of a phone
 * on a 3G connection — exactly the budget docs/frontend-plan.md §5 exists to
 * protect.
 */

/**
 * CSRF token for state-changing fetch() calls from JS.
 * Blade forms use @csrf and do not need this.
 *
 * @returns {string} the token, or '' if the meta tag is absent
 */
export function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}
