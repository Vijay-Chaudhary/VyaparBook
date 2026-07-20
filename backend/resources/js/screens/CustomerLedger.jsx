import { formatDate, t } from '../i18n';
import { formatRupees } from '../offline/money';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * One customer's khata: the running statement plus the two actions that
 * actually get used at the counter.
 */
export function CustomerLedger({ customer, entries, outstandingPaise }) {
    if (!customer) {
        return (
            <Screen title={t('khata')} onBack={() => navigate('/khata')}>
                <p className="text-ink-muted">{t('loading')}</p>
            </Screen>
        );
    }

    return (
        <Screen title={customer.name} onBack={() => navigate('/khata')}>
            <div className="card mb-3">
                <p className="text-sm text-ink-muted">{t('balance')}</p>
                {/* null means "not computed yet". Rendering ₹0.00 in that gap
                    would show a wrong balance a shopkeeper might act on. */}
                {outstandingPaise === null ? (
                    <p className="text-2xl font-bold text-ink-muted" data-testid="outstanding">
                        {t('loading')}
                    </p>
                ) : (
                    <p
                        className={`tabular text-2xl font-bold ${
                            outstandingPaise > 0 ? 'text-danger' : 'text-success'
                        }`}
                        data-testid="outstanding"
                    >
                        {formatRupees(outstandingPaise)}
                    </p>
                )}

                {customer.village && <p className="mt-1 text-sm text-ink-muted">{customer.village}</p>}
                {customer.phone && (
                    /* tel: link — calling about a due is the most common next
                       action after looking at a balance. */
                    <a href={`tel:${customer.phone}`} className="tabular mt-1 inline-block text-sm text-brand">
                        {customer.phone}
                    </a>
                )}
            </div>

            <div className="mb-4 flex gap-2">
                <button
                    type="button"
                    className="btn-primary flex-1"
                    onClick={() => navigate(`/payment/${customer.uuid}`)}
                    data-testid="record-payment"
                >
                    {t('record_payment')}
                </button>
                <button
                    type="button"
                    className="btn-secondary flex-1"
                    onClick={() => navigate(`/sale/${customer.uuid}`)}
                    data-testid="record-sale"
                >
                    {t('record_sale')}
                </button>
            </div>

            <h2 className="mb-2 text-sm font-semibold text-ink-muted">{t('ledger')}</h2>

            {entries.length === 0 ? (
                <p className="card text-center text-ink-muted">{t('no_entries')}</p>
            ) : (
                <ul className="space-y-2" data-testid="ledger">
                    {/* Newest first: the recent end of a khata is what gets
                        checked, and it saves scrolling past years of history. */}
                    {[...entries].reverse().map((entry) => (
                        <li key={entry.uuid} className="card flex items-center gap-3 py-3">
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">{t(entry.kind)}</span>
                                <span className="block text-sm text-ink-muted">
                                    {formatDate(entry.date)}
                                    {entry.pending && (
                                        /* Never hide unsynced work — say it is
                                           queued, so the total is explicable. */
                                        <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-warning">
                                            {t('pending_sync')}
                                        </span>
                                    )}
                                </span>
                            </span>

                            <span className="shrink-0 text-right">
                                <span
                                    className={`tabular block font-medium ${
                                        entry.deltaPaise > 0 ? 'text-ink' : 'text-success'
                                    }`}
                                >
                                    {entry.deltaPaise > 0 ? '+' : '−'}
                                    {formatRupees(Math.abs(entry.deltaPaise), { withSymbol: false })}
                                </span>
                                <span className="tabular block text-xs text-ink-muted">
                                    {formatRupees(entry.runningPaise)}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Screen>
    );
}
