import './bootstrap';

/*
 * The offline-capable screens live under /app/*, so the React bundle is only
 * fetched there. A Blade page (login, billing, admin) must not pay for a
 * runtime it never mounts — that is most of the point of the Blade/React split
 * in docs/frontend-plan.md §1.
 */
if (document.getElementById('app-root')) {
    import('./main.jsx');
}
