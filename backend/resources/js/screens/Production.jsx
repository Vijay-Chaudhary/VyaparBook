import { useState } from 'react';
import { formatDate, getLocale, t, today } from '../i18n';
import { productName } from '../offline/catalog';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * Production: the manufacturing log and batch entry. Manager-only and
 * online-only, exactly like stock — a batch draws materials down on the server,
 * so it goes straight to the REST API and reports the verdict.
 */

export function ProductionList({ batches, online }) {
    return (
        <Screen
            title={t('production')}
            action={
                <button
                    type="button"
                    className="btn-primary px-3 disabled:opacity-40"
                    onClick={() => navigate('/batch/new')}
                    disabled={!online}
                    title={online ? undefined : t('online_only')}
                >
                    + {t('record_batch')}
                </button>
            }
        >
            {!online && (
                <p role="status" className="mb-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-warning">
                    {t('online_only')}
                </p>
            )}

            {batches.length === 0 ? (
                <p className="card text-center text-ink-muted">{t('no_batches')}</p>
            ) : (
                <ul className="space-y-2" data-testid="batch-list">
                    {batches.map((batch) => (
                        <li key={batch.id} className="card flex items-center gap-3 py-3">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate font-medium">
                                    {batch.product_name ?? t('product')}
                                </span>
                                <span className="block text-sm text-ink-muted">
                                    {formatDate(batch.batch_date)}
                                </span>
                            </span>
                            <span className="tabular shrink-0 font-semibold">
                                {batch.output_kg} kg
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Screen>
    );
}

/* ------------------------------------------------------------------ */

export function RecordBatch({ products, materials, onSave }) {
    const [productId, setProductId] = useState('');
    const [outputKg, setOutputKg] = useState('');
    const [date, setDate] = useState(today());
    const [lines, setLines] = useState([{ raw_material_id: '', qty: '' }]);
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);
    // Stable across retries so a lost response dedupes on the server rather than
    // the shop recording a phantom second batch that drew stock twice.
    const [uuid] = useState(() => crypto.randomUUID());

    const setLine = (i, key) => (e) =>
        setLines((cur) => cur.map((l, idx) => (idx === i ? { ...l, [key]: e.target.value } : l)));

    const submit = async (e) => {
        e.preventDefault();

        const consumptions = lines
            .filter((l) => l.raw_material_id && Number(l.qty) > 0)
            .map((l) => ({ raw_material_id: l.raw_material_id, qty: l.qty.trim() }));

        // Server rules: a product, positive output, at least one consumption.
        if (!productId || !(Number(outputKg) > 0) || consumptions.length === 0) {
            setError(t('required'));
            return;
        }

        setSaving(true);
        setError(null);

        const result = await onSave({
            uuid,
            product_id: productId,
            batch_date: date,
            output_kg: outputKg.trim(),
            consumptions,
        });

        if (result.ok) {
            navigate('/production');
            return;
        }

        if (result.upgrade) setError(t('needs_upgrade'));
        else if (result.offline) setError(t('try_again_online'));
        else setError(result.message ?? t('try_again_online'));

        setSaving(false);
    };

    if (products.length === 0) {
        return (
            <Screen title={t('new_batch')} onBack={() => navigate('/production')}>
                <p className="card text-center text-warning">{t('no_products')}</p>
            </Screen>
        );
    }

    return (
        <Screen title={t('new_batch')} onBack={() => navigate('/production')}>
            <form onSubmit={submit} className="card space-y-4" noValidate>
                {error && <p role="alert" className="field-error">{error}</p>}

                <div>
                    <label htmlFor="product" className="field-label">{t('product')}</label>
                    <select id="product" className="field-input" value={productId}
                            onChange={(e) => setProductId(e.target.value)} data-testid="batch-product">
                        <option value="">—</option>
                        {products.map((p) => (
                            <option key={p.id} value={p.id}>{productName(p, getLocale())}</option>
                        ))}
                    </select>
                </div>

                <div>
                    <label htmlFor="output" className="field-label">{t('output_kg')}</label>
                    <input id="output" inputMode="decimal" className="field-input tabular"
                           value={outputKg} onChange={(e) => setOutputKg(e.target.value)}
                           placeholder="0" data-testid="batch-output" />
                </div>

                <fieldset className="space-y-2">
                    <legend className="field-label">{t('materials_used')}</legend>
                    {lines.map((line, i) => (
                        <div key={i} className="flex gap-2">
                            <select className="field-input min-w-0 flex-1" value={line.raw_material_id}
                                    onChange={setLine(i, 'raw_material_id')} data-testid={`batch-mat-${i}`}>
                                <option value="">—</option>
                                {materials.map((m) => (
                                    <option key={m.id} value={m.id}>{m.name}</option>
                                ))}
                            </select>
                            <input inputMode="decimal" className="field-input tabular w-24"
                                   value={line.qty} onChange={setLine(i, 'qty')}
                                   placeholder={t('qty')} data-testid={`batch-qty-${i}`} />
                        </div>
                    ))}
                    <button type="button" className="btn-secondary w-full"
                            onClick={() => setLines((l) => [...l, { raw_material_id: '', qty: '' }])}>
                        + {t('add_consumption')}
                    </button>
                </fieldset>

                <div>
                    <label htmlFor="date" className="field-label">{t('date')}</label>
                    <input id="date" type="date" className="field-input tabular"
                           value={date} onChange={(e) => setDate(e.target.value)} />
                </div>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-batch">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}
