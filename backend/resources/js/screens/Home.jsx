import { t, today } from '../i18n';
import { formatRupees } from '../offline/money';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * Today at a glance, then straight into the two things that get done all day.
 *
 * Deliberately thin: the shopkeeper opens the app to record something or to
 * check who owes, not to read a dashboard.
 */
export function Home({ userName, customers, entriesToday }) {
    const totalDue = customers.reduce((sum, c) => sum + Math.max(0, c.outstandingPaise), 0);

    const salesTodayPaise = entriesToday
        .filter((e) => e.kind === 'sale')
        .reduce((sum, e) => sum + e.deltaPaise, 0);

    const collectedTodayPaise = entriesToday
        .filter((e) => e.kind === 'payment')
        .reduce((sum, e) => sum + Math.abs(e.deltaPaise), 0);

    return (
        <Screen title={`नमस्ते, ${userName}`}>
            <div className="mb-3 grid grid-cols-2 gap-2">
                <div className="card">
                    <p className="text-sm text-ink-muted">{t('sales')}</p>
                    <p className="tabular text-lg font-bold" data-testid="sales-today">
                        {formatRupees(salesTodayPaise)}
                    </p>
                </div>

                <div className="card">
                    <p className="text-sm text-ink-muted">{t('record_payment')}</p>
                    <p className="tabular text-lg font-bold text-success" data-testid="collected-today">
                        {formatRupees(collectedTodayPaise)}
                    </p>
                </div>
            </div>

            <button
                type="button"
                onClick={() => navigate('/khata')}
                className="card mb-3 flex w-full items-center gap-3 text-left"
            >
                <span className="flex-1">
                    <span className="block text-sm text-ink-muted">{t('total_due')}</span>
                    <span className="tabular block text-2xl font-bold text-danger">
                        {formatRupees(totalDue)}
                    </span>
                </span>
                <span className="text-sm text-brand">{t('khata')} →</span>
            </button>

            <div className="flex gap-2">
                <button
                    type="button"
                    className="btn-primary flex-1"
                    onClick={() => navigate('/customer/new')}
                >
                    {t('add_customer')}
                </button>
            </div>

            <p className="mt-4 text-center text-xs text-ink-muted tabular">{today()}</p>
        </Screen>
    );
}
