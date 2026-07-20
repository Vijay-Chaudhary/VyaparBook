import { useMemo, useState } from 'react';
import { t } from '../i18n';
import { formatRupees } from '../offline/money';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * "Who owes me" — the screen a shopkeeper opens most.
 *
 * Sorted by outstanding, highest first, because that is the list they act on.
 * Search filters name, village and phone, since a shop remembers a customer by
 * any of the three.
 */
export function KhataList({ customers }) {
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (!needle) return customers;

        return customers.filter((c) =>
            [c.name, c.village, c.phone].filter(Boolean).some((field) =>
                String(field).toLowerCase().includes(needle)
            )
        );
    }, [customers, query]);

    const totalDue = customers.reduce((sum, c) => sum + Math.max(0, c.outstandingPaise), 0);

    return (
        <Screen
            title={t('khata')}
            action={
                <button type="button" className="btn-primary px-3" onClick={() => navigate('/customer/new')}>
                    + {t('add_customer')}
                </button>
            }
        >
            <div className="card mb-3">
                <p className="text-sm text-ink-muted">{t('total_due')}</p>
                <p className="tabular text-2xl font-bold" data-testid="total-due">
                    {formatRupees(totalDue)}
                </p>
            </div>

            {customers.length > 0 && (
                <label className="mb-3 block">
                    <span className="sr-only">{t('search_customers')}</span>
                    <input
                        type="search"
                        className="field-input"
                        placeholder={t('search_customers')}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        data-testid="search"
                    />
                </label>
            )}

            {customers.length === 0 ? (
                /* Empty state that tells them what to do next, not just that
                   there is nothing (SKILL.md §8 empty-states). */
                <div className="card text-center">
                    <p className="font-medium">{t('no_customers')}</p>
                    <p className="mt-1 text-sm text-ink-muted">{t('no_customers_hint')}</p>
                    <button
                        type="button"
                        className="btn-primary mt-3 w-full"
                        onClick={() => navigate('/customer/new')}
                    >
                        {t('add_customer')}
                    </button>
                </div>
            ) : (
                <ul className="space-y-2" data-testid="customer-list">
                    {filtered.map((customer) => (
                        <li key={customer.uuid}>
                            <button
                                type="button"
                                onClick={() => navigate(`/khata/${customer.uuid}`)}
                                className="card flex min-h-tap w-full items-center gap-3 text-left
                                           active:bg-canvas"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">{customer.name}</span>
                                    {customer.village && (
                                        <span className="block truncate text-sm text-ink-muted">
                                            {customer.village}
                                        </span>
                                    )}
                                </span>

                                <span
                                    className={`tabular shrink-0 font-semibold ${
                                        customer.outstandingPaise > 0 ? 'text-danger' : 'text-success'
                                    }`}
                                >
                                    {formatRupees(customer.outstandingPaise)}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Screen>
    );
}
