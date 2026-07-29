import { useState } from 'react';
import { formatDate, t, today } from '../i18n';
import { formatQty } from '../offline/qty';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

const UNITS = ['kg', 'litre', 'piece', 'gram', 'ml', 'packet'];

/**
 * Stock write forms. Online-only — each submits to the REST API and reports the
 * server's verdict. onSave returns the apiWrite result so the form can show a
 * plan-upgrade (402) or offline message rather than a generic failure.
 */

function useSubmit(onSave, afterNavigate) {
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const submit = async (body) => {
        setSaving(true);
        setError(null);

        const result = await onSave(body);

        if (result.ok) {
            navigate(afterNavigate);
            return;
        }

        // Map the structured result to one clear line (SKILL.md §8 error-clarity).
        if (result.upgrade) setError(t('needs_upgrade'));
        else if (result.offline) setError(t('try_again_online'));
        else setError(result.message ?? t('try_again_online'));

        setSaving(false);
    };

    return { submit, error, saving };
}

/* ------------------------------------------------------------------ */

export function NewMaterial({ onSave }) {
    const [values, setValues] = useState({ name: '', unit: 'kg', reorder_level: '' });
    const { submit, error, saving } = useSubmit(onSave, '/stock');

    const set = (key) => (e) => setValues((v) => ({ ...v, [key]: e.target.value }));

    const handle = (e) => {
        e.preventDefault();
        if (!values.name.trim()) return;

        submit({
            name: values.name.trim(),
            unit: values.unit,
            reorder_level: values.reorder_level.trim() || null,
        });
    };

    return (
        <Screen title={t('new_material')} onBack={() => navigate('/stock')}>
            <form onSubmit={handle} className="card space-y-4" noValidate>
                {error && <p role="alert" className="field-error">{error}</p>}

                <div>
                    <label htmlFor="name" className="field-label">{t('material_name')}</label>
                    <input id="name" className="field-input" value={values.name} onChange={set('name')}
                           autoFocus data-testid="material-name" />
                </div>

                <div>
                    <label htmlFor="unit" className="field-label">{t('unit')}</label>
                    <select id="unit" className="field-input" value={values.unit} onChange={set('unit')}>
                        {UNITS.map((u) => <option key={u} value={u}>{u}</option>)}
                    </select>
                </div>

                <div>
                    <label htmlFor="reorder" className="field-label">{t('reorder_level')}</label>
                    <input id="reorder" inputMode="decimal" className="field-input tabular"
                           value={values.reorder_level} onChange={set('reorder_level')} placeholder="0" />
                </div>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-material">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}

/* ------------------------------------------------------------------ */

export function RecordMovement({ material, onSave }) {
    const [kind, setKind] = useState('in');
    const [qty, setQty] = useState('');
    const [date, setDate] = useState(today());
    const [note, setNote] = useState('');
    // Stable for the life of this form: a retry after a lost response reuses it,
    // so the server dedupes instead of the shop double-counting stock. A fresh
    // per-tap uuid would defeat that.
    const [uuid] = useState(() => crypto.randomUUID());
    const { submit, error, saving } = useSubmit(onSave, `/stock/${material.id}`);

    const handle = (e) => {
        e.preventDefault();
        if (!qty.trim()) return;

        // Server derives the sign from kind (in → +, out → −), so send the
        // magnitude and let it decide — mirrors StockMovementController.
        submit({
            uuid,
            raw_material_id: material.id,
            movement_date: date,
            kind,
            qty: qty.trim(),
            note: note.trim() || null,
        });
    };

    return (
        <Screen title={t('record_movement')} onBack={() => navigate(`/stock/${material.id}`)}>
            <form onSubmit={handle} className="card space-y-4" noValidate>
                <p className="text-sm text-ink-muted">{material.name}</p>
                {error && <p role="alert" className="field-error">{error}</p>}

                <div>
                    <span className="field-label">{t('record_movement')}</span>
                    {/* Segmented in/out/adjust — the whole choice visible, no
                        hidden dropdown for a 3-way toggle used constantly. */}
                    <div className="flex gap-2" role="radiogroup">
                        {[
                            ['in', t('movement_in')],
                            ['out', t('movement_out')],
                            ['adjust', t('movement_adjust')],
                        ].map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                role="radio"
                                aria-checked={kind === value}
                                onClick={() => setKind(value)}
                                data-testid={`kind-${value}`}
                                className={`min-h-tap flex-1 rounded-lg border text-sm font-medium ${
                                    kind === value
                                        ? 'border-brand bg-brand/5 text-brand'
                                        : 'border-hairline text-ink-muted'
                                }`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                <div>
                    <label htmlFor="qty" className="field-label">
                        {t('qty')} ({material.unit})
                    </label>
                    <input id="qty" inputMode="decimal" className="field-input tabular text-lg"
                           value={qty} onChange={(e) => setQty(e.target.value)} autoFocus
                           placeholder="0" data-testid="movement-qty" />
                </div>

                <div>
                    <label htmlFor="date" className="field-label">{t('date')}</label>
                    <input id="date" type="date" className="field-input tabular"
                           value={date} onChange={(e) => setDate(e.target.value)} />
                </div>

                <div>
                    <label htmlFor="note" className="field-label">{t('note')}</label>
                    <input id="note" className="field-input" value={note}
                           onChange={(e) => setNote(e.target.value)} maxLength={255} />
                </div>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-movement">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}

/* ------------------------------------------------------------------ */

export function MaterialDetail({ material, movements, online, onReverse }) {
    const [error, setError] = useState(null);
    const [busy, setBusy] = useState(null);

    // A refusal must reach the screen. The server declines a correction that is
    // itself a correction, one already corrected, and any movement a batch or
    // purchase created — each with a reason worth reading.
    const reverse = async (id) => {
        setError(null);
        setBusy(id);

        const result = await onReverse(id);

        setBusy(null);
        if (!result.ok) setError(result.message ?? t('correction_failed'));
    };

    if (!material) {
        return (
            <Screen title={t('stock')} onBack={() => navigate('/stock')}>
                <p className="text-ink-muted">{t('loading')}</p>
            </Screen>
        );
    }

    const onHand = movements.length ? movements[movements.length - 1].runningMilli : 0;

    return (
        <Screen title={material.name} onBack={() => navigate('/stock')}>
            <div className="card mb-3">
                <p className="text-sm text-ink-muted">{t('on_hand')}</p>
                <p className="tabular text-2xl font-bold" data-testid="on-hand">
                    {formatQty(onHand, material.unit)}
                </p>
            </div>

            <button
                type="button"
                className="btn-primary mb-4 w-full disabled:opacity-40"
                onClick={() => navigate(`/movement/${material.id}`)}
                disabled={!online}
                title={online ? undefined : t('online_only')}
                data-testid="record-movement"
            >
                {t('record_movement')}
            </button>

            {error && (
                <p role="alert" className="card mb-3 text-sm text-danger" data-testid="movement-error">
                    {error}
                </p>
            )}

            {movements.length === 0 ? (
                <p className="card text-center text-ink-muted">{t('no_entries')}</p>
            ) : (
                <ul className="space-y-2">
                    {[...movements].reverse().map((movement) => (
                        <li key={movement.id} className="card flex items-center gap-3 py-3">
                            <span className="min-w-0 flex-1">
                                <span className="block font-medium">
                                    {t(`movement_${movement.kind}`)}
                                </span>
                                <span className="block text-sm text-ink-muted">
                                    {formatDate(movement.movement_date)}
                                </span>
                            </span>
                            <span className="shrink-0 text-right">
                                <span className={`tabular block font-medium ${
                                    movement.deltaMilli >= 0 ? 'text-success' : 'text-danger'
                                }`}>
                                    {movement.deltaMilli >= 0 ? '+' : '−'}
                                    {formatQty(Math.abs(movement.deltaMilli))}
                                </span>
                                {movement.isReversal ? (
                                    <span className="block text-xs text-ink-muted">{t('is_correction')}</span>
                                ) : movement.isReversed ? (
                                    <span className="block text-xs text-ink-muted">{t('corrected')}</span>
                                ) : (
                                    <button
                                        type="button"
                                        className="text-xs text-danger disabled:opacity-40"
                                        disabled={!online || busy === movement.id}
                                        onClick={() => reverse(movement.id)}
                                        data-testid={`reverse-movement-${movement.id}`}
                                    >
                                        {busy === movement.id ? t('loading') : t('reverse')}
                                    </button>
                                )}
                                <span className="tabular block text-xs text-ink-muted">
                                    {formatQty(movement.runningMilli, material.unit)}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Screen>
    );
}
