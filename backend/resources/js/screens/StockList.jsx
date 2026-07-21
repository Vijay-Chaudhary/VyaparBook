import { t } from '../i18n';
import { formatQty } from '../offline/qty';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * Raw materials with on-hand and a low-stock flag — a manager's "what do I need
 * to buy" screen. Reads come from the cache and work offline; the write actions
 * it links to are online-only (stock has no offline path).
 */
export function StockList({ materials, online }) {
    return (
        <Screen
            title={t('stock')}
            action={
                <button
                    type="button"
                    className="btn-primary px-3 disabled:opacity-40"
                    onClick={() => navigate('/material/new')}
                    disabled={!online}
                    title={online ? undefined : t('online_only')}
                >
                    + {t('add_material')}
                </button>
            }
        >
            {/* Stock changes need the network; say so once, up top, rather than
                letting a tap fail silently later. */}
            {!online && (
                <p role="status" className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-warning">
                    {t('online_only')}
                </p>
            )}

            {materials.length === 0 ? (
                <div className="card text-center">
                    <p className="font-medium">{t('no_materials')}</p>
                    <p className="mt-1 text-sm text-ink-muted">{t('no_materials_hint')}</p>
                </div>
            ) : (
                <ul className="space-y-2" data-testid="material-list">
                    {materials.map((material) => (
                        <li key={material.id}>
                            <button
                                type="button"
                                onClick={() => navigate(`/stock/${material.id}`)}
                                className="card flex min-h-tap w-full items-center gap-3 text-left"
                            >
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate font-medium">{material.name}</span>
                                    {material.belowReorder && (
                                        <span className="text-xs font-medium text-danger">
                                            {t('low_stock')}
                                        </span>
                                    )}
                                </span>

                                <span
                                    className={`tabular shrink-0 font-semibold ${
                                        material.belowReorder ? 'text-danger' : 'text-ink'
                                    }`}
                                >
                                    {formatQty(material.onHandMilli, material.unit)}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </Screen>
    );
}
