import { t } from '../i18n';
import { navigate } from '../router';

/**
 * App chrome: the sync status strip and the bottom navigation.
 *
 * Bottom nav rather than a top bar because the app is used one-handed while
 * serving a customer — the thumb reaches the bottom of a phone, not the top
 * (PRD §15). Capped at 5 items (SKILL.md §9 bottom-nav-limit).
 */

const TABS = [
    { route: '/', key: 'home', icon: HomeIcon },
    { route: '/khata', key: 'khata', icon: BookIcon },
    { route: '/sale', key: 'sales', icon: PlusIcon },
    // Manager-only. Hidden for salesman/accountant, matching StockPolicy and
    // what sync/pull actually sends them — never render a tab that 403s.
    { route: '/stock', key: 'stock', icon: BoxIcon, managerOnly: true },
    { route: '/production', key: 'production', icon: FlaskIcon, managerOnly: true },
];

export function BottomNav({ path, canManageStock = false }) {
    const tabs = TABS.filter((tab) => !tab.managerOnly || canManageStock);

    return (
        <nav
            aria-label={t('khata')}
            /* pb-safe: keep the tab row clear of the iOS home indicator. */
            className="fixed inset-x-0 bottom-0 z-20 border-t border-hairline bg-surface
                       pb-[env(safe-area-inset-bottom)]"
        >
            <ul className="mx-auto flex max-w-md">
                {tabs.map(({ route, key, icon: Icon }) => {
                    const active = route === '/' ? path === '/' : path.startsWith(route);

                    return (
                        <li key={key} className="flex-1">
                            <button
                                type="button"
                                onClick={() => navigate(route)}
                                /* aria-current so a screen reader announces the
                                   active tab, not just its colour. */
                                aria-current={active ? 'page' : undefined}
                                className={`flex min-h-tap w-full flex-col items-center justify-center gap-0.5 py-2
                                            ${active ? 'text-brand' : 'text-ink-muted'}`}
                            >
                                <Icon filled={active} />
                                {/* Icon AND label: an icon-only nav is a guessing
                                    game for a non-technical user (SKILL.md §9). */}
                                <span className={`text-xs ${active ? 'font-semibold' : ''}`}>
                                    {t(key)}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}

/**
 * Connectivity and queue state.
 *
 * Always visible rather than only on failure: a shopkeeper needs to know their
 * work is queued *before* they wonder, not after they have re-entered a sale.
 */
export function SyncBar({ online, queued, syncing, error, onSync }) {
    return (
        <div
            className="sticky top-0 z-10 flex flex-wrap items-center gap-x-2 border-b border-hairline
                       bg-surface px-4 py-2 text-sm"
            aria-live="polite"
        >
            {/*
              * A failing sync must be visible. This was silent once already: a
              * schema bug made every pull roll back, the app cached nothing,
              * and the UI cheerfully showed "online" with no hint anything was
              * wrong.
              */}
            {error && online && (
                <p role="status" className="w-full text-danger" data-testid="sync-error">
                    {error}
                </p>
            )}

            <span className={online ? 'text-success' : 'text-warning'} data-testid="net">
                {online ? `● ${t('online')}` : `○ ${t('offline_safe')}`}
            </span>

            {queued > 0 && (
                <span className="text-ink-muted" data-testid="queued-label">
                    · {t('queued')}: <span className="tabular font-medium" data-testid="queued">{queued}</span>
                </span>
            )}

            <button
                type="button"
                onClick={onSync}
                disabled={!online || syncing}
                className="ml-auto min-h-tap px-2 font-medium text-brand disabled:opacity-40"
                data-testid="sync"
            >
                {syncing ? t('syncing') : t('sync_now')}
            </button>
        </div>
    );
}

/**
 * Switch business (PRD §15). Only rendered for a user in more than one.
 *
 * The guard (PRD §9): switching is refused while the outbox has unsynced work,
 * or that work would be stranded in a cache about to be closed. onSwitch runs
 * assertSafeToSwitch and throws with a message if not safe.
 */
export function BusinessSwitcher({ current, memberships, onSwitch }) {
    if (memberships.length < 2) return null;

    const change = async (event) => {
        const id = event.target.value;

        if (id === current) return;

        try {
            await onSwitch(id);
        } catch (error) {
            // Reset the select to the current business and surface why.
            event.target.value = current;
            alert(error.message); // eslint-disable-line no-alert -- deliberate hard stop
        }
    };

    return (
        <label className="flex items-center gap-1 border-b border-hairline bg-canvas px-4 py-1.5 text-sm">
            <span className="sr-only">{t('switch_business')}</span>
            <select
                value={current ?? ''}
                onChange={change}
                className="min-h-tap max-w-full bg-transparent font-medium text-ink"
                data-testid="business-switcher"
            >
                {memberships.map(({ business }) => (
                    <option key={business.id} value={business.id}>
                        {business.name}
                    </option>
                ))}
            </select>
        </label>
    );
}

export function StalenessBanner({ staleness }) {
    if (staleness === 'ok') return null;

    const blocked = staleness === 'blocked';

    return (
        <div
            role={blocked ? 'alert' : 'status'}
            className={`px-4 py-2 text-sm ${blocked ? 'bg-red-50 text-danger' : 'bg-amber-50 text-warning'}`}
        >
            {blocked ? t('stale_blocked') : t('stale_warn')}
        </div>
    );
}

/** Screen scaffold: title, optional back button, and room for the fixed nav. */
export function Screen({ title, onBack, action, children }) {
    return (
        /* pb-24 so content is never hidden behind the fixed bottom nav
           (SKILL.md §5 fixed-element-offset). */
        <div className="mx-auto max-w-md px-4 pb-24 pt-3">
            <div className="mb-3 flex items-center gap-2">
                {onBack && (
                    <button
                        type="button"
                        onClick={onBack}
                        aria-label={t('back')}
                        className="-ml-2 flex min-h-tap min-w-tap items-center justify-center text-ink-muted"
                    >
                        <ChevronLeft />
                    </button>
                )}

                <h1 className="text-xl font-bold">{title}</h1>

                {action && <div className="ml-auto">{action}</div>}
            </div>

            {children}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/* Icons — inline SVG, one stroke width, never emoji (SKILL.md §4)     */
/* ------------------------------------------------------------------ */

const stroke = {
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.75,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
};

function HomeIcon({ filled }) {
    return (
        <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" {...stroke}
             fill={filled ? 'currentColor' : 'none'} fillOpacity={filled ? 0.12 : 0}>
            <path d="M3 10.5 12 3l9 7.5" />
            <path d="M5 9.5V21h14V9.5" />
        </svg>
    );
}

function BookIcon({ filled }) {
    return (
        <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" {...stroke}
             fill={filled ? 'currentColor' : 'none'} fillOpacity={filled ? 0.12 : 0}>
            <path d="M4 4h11a3 3 0 0 1 3 3v13H7a3 3 0 0 1-3-3z" />
            <path d="M8 8h7M8 12h7" />
        </svg>
    );
}

function PlusIcon({ filled }) {
    return (
        <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" {...stroke}
             fill={filled ? 'currentColor' : 'none'} fillOpacity={filled ? 0.12 : 0}>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v8M8 12h8" />
        </svg>
    );
}

function FlaskIcon({ filled }) {
    return (
        <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" {...stroke}
             fill={filled ? 'currentColor' : 'none'} fillOpacity={filled ? 0.12 : 0}>
            <path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 1.8 3h10.4a2 2 0 0 0 1.8-3l-5-9V3" />
            <path d="M7.5 14h9" />
        </svg>
    );
}

function BoxIcon({ filled }) {
    return (
        <svg width="22" height="22" viewBox="0 0 24 24" aria-hidden="true" {...stroke}
             fill={filled ? 'currentColor' : 'none'} fillOpacity={filled ? 0.12 : 0}>
            <path d="M3 8l9-5 9 5v8l-9 5-9-5z" />
            <path d="M3 8l9 5 9-5M12 13v8" />
        </svg>
    );
}

function ChevronLeft() {
    return (
        <svg width="24" height="24" viewBox="0 0 24 24" aria-hidden="true" {...stroke}>
            <path d="M15 18l-6-6 6-6" />
        </svg>
    );
}
