import { formatDate, t } from '../i18n';
import { actionsFor, groupByStatus, ORDER_STATUSES } from '../offline/orders';
import { formatRupees, toPaise } from '../offline/money';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * The salesman's orders, grouped by status, offering only the actions each
 * state allows. A pending order shows why it has no actions rather than a
 * mysterious disabled button — the acceptance simply has not synced yet.
 */
export function Orders({ orders, customersById, onAction, canAccept = false }) {
    const grouped = groupByStatus(orders);

    return (
        <Screen title={t('orders')} onBack={() => navigate('/khata')}>
            {/* Accepting is an online-only Blade screen OUTSIDE this app, so an
                owner landing here can only be pointed at it. Without this link
                they see a list of orders "waiting for the owner" and no way to
                be that owner. A plain <a>, because it leaves the SPA. */}
            {canAccept && (
                <a href="/orders" className="btn-secondary mb-3 block text-center" data-testid="go-accept-orders">
                    {t('accept_orders')}
                </a>
            )}

            {/* The nav tab lands here, so this is where a new order starts too. */}
            <button
                type="button"
                className="btn-primary mb-4 w-full"
                onClick={() => navigate('/order')}
                data-testid="take-order"
            >
                + {t('take_order')}
            </button>

            {ORDER_STATUSES.map((status) => (
                grouped[status].length > 0 && (
                    <section key={status} className="mb-4">
                        <h2 className="mb-2 text-sm font-semibold text-ink-muted">{t(status)}</h2>
                        <ul className="space-y-2">
                            {grouped[status].map((order) => (
                                <li key={order.uuid} className="card py-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <span className="min-w-0 flex-1">
                                            <span className="block font-medium">
                                                {customersById.get(order.customer_id)?.name ?? '—'}
                                            </span>
                                            <span className="block text-sm text-ink-muted">{formatDate(order.order_date)}</span>
                                        </span>
                                        <span className="tabular shrink-0 font-medium">
                                            {formatRupees(toPaise(order.total ?? '0'))}
                                        </span>
                                    </div>

                                    {status === 'pending' && (
                                        <p className="mt-1 text-xs text-ink-muted">{t('awaiting_acceptance')}</p>
                                    )}

                                    <div className="mt-2 flex gap-2">
                                        {actionsFor(status).map((action) => (
                                            <button
                                                key={action}
                                                type="button"
                                                className={action === 'cancel' ? 'text-xs text-danger' : 'btn-secondary'}
                                                onClick={() => onAction(order, action)}
                                                data-testid={`order-${action}-${order.uuid}`}
                                            >
                                                {t(action === 'cancel' ? 'cancel_order' : action)}
                                            </button>
                                        ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </section>
                )
            ))}
        </Screen>
    );
}
