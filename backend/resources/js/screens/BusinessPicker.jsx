import { t } from '../i18n';

/**
 * Shown when the token is tenant-less — a user who belongs to more than one
 * business and has not chosen which to open.
 *
 * Without this the app would hang on "loading": main.jsx only opens a Dexie
 * cache once a tenant is resolved, and a multi-business user has none until
 * they pick here.
 */
export function BusinessPicker({ memberships, onPick }) {
    return (
        <div className="mx-auto max-w-md px-4 py-8">
            <h1 className="mb-6 text-2xl font-bold">{t('choose_business')}</h1>

            <ul className="space-y-2">
                {memberships.map(({ business, role }) => (
                    <li key={business.id}>
                        <button
                            type="button"
                            onClick={() => onPick(business.id)}
                            className="card flex min-h-tap w-full items-center gap-3 text-left active:bg-canvas"
                        >
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">{business.name}</span>
                                {business.city && (
                                    <span className="block truncate text-sm text-ink-muted">
                                        {business.city}
                                    </span>
                                )}
                            </span>
                            <span className="shrink-0 text-sm text-ink-muted">{t(role)}</span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}
