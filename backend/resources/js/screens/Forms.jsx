import { useState } from 'react';
import { t, today } from '../i18n';
import { formatRupees, toPaise } from '../offline/money';
import { navigate } from '../router';
import { Screen } from '../components/Chrome';

/**
 * The three data-entry forms. All work fully offline: they write to the outbox
 * and return immediately, never awaiting a network round trip.
 *
 * Shared rules across all three (SKILL.md §8):
 *  - visible labels, never placeholder-only
 *  - errors below the field they belong to, announced to screen readers
 *  - inputMode="decimal" so a phone shows a numeric keypad for money
 *  - 16px inputs (.field-input) so iOS does not auto-zoom on focus
 */

function Field({ id, label, error, children, hint }) {
    return (
        <div>
            <label htmlFor={id} className="field-label">
                {label}
            </label>
            {children}
            {hint && !error && <p className="mt-1 text-sm text-ink-muted">{hint}</p>}
            {error && (
                <p id={`${id}-error`} role="alert" className="field-error">
                    {error}
                </p>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */

export function NewCustomer({ onSave }) {
    const [values, setValues] = useState({ name: '', village: '', phone: '', opening_balance: '' });
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const set = (key) => (e) => setValues((v) => ({ ...v, [key]: e.target.value }));

    const submit = async (e) => {
        e.preventDefault();

        if (!values.name.trim()) {
            setErrors({ name: t('required') });
            document.getElementById('name')?.focus(); // §8 focus-management
            return;
        }

        setSaving(true);

        try {
            await onSave({
                name: values.name.trim(),
                village: values.village.trim() || null,
                phone: values.phone.trim() || null,
                opening_balance: values.opening_balance.trim() || '0',
            });
            navigate('/khata');
        } catch (error) {
            setErrors({ form: error.message });
            setSaving(false);
        }
    };

    return (
        <Screen title={t('new_customer')} onBack={() => navigate('/khata')}>
            <form onSubmit={submit} className="card space-y-4" noValidate>
                {errors.form && (
                    <p role="alert" className="field-error">
                        {errors.form}
                    </p>
                )}

                <Field id="name" label={t('name')} error={errors.name}>
                    <input
                        id="name"
                        className="field-input"
                        value={values.name}
                        onChange={set('name')}
                        autoFocus
                        aria-invalid={errors.name ? 'true' : undefined}
                        aria-describedby={errors.name ? 'name-error' : undefined}
                        data-testid="customer-name"
                    />
                </Field>

                <Field id="village" label={t('village')}>
                    <input id="village" className="field-input" value={values.village} onChange={set('village')} />
                </Field>

                <Field id="phone" label={t('phone')}>
                    <input
                        id="phone"
                        type="tel"
                        inputMode="tel"
                        className="field-input tabular"
                        value={values.phone}
                        onChange={set('phone')}
                    />
                </Field>

                <Field
                    id="opening_balance"
                    label={t('opening_balance')}
                    hint={t('opening')}
                >
                    <input
                        id="opening_balance"
                        inputMode="decimal"
                        className="field-input tabular"
                        value={values.opening_balance}
                        onChange={set('opening_balance')}
                        placeholder="0.00"
                    />
                </Field>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-customer">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}

/* ------------------------------------------------------------------ */

export function RecordPayment({ customer, onSave }) {
    const [amount, setAmount] = useState('');
    const [mode, setMode] = useState('cash');
    const [date, setDate] = useState(today());
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const submit = async (e) => {
        e.preventDefault();

        const paise = (() => {
            try {
                return toPaise(amount);
            } catch {
                return 0;
            }
        })();

        // Server rule: amount must be gt:0. Catch it here so an offline entry
        // is never queued only to be parked on the next sync.
        if (paise <= 0) {
            setError(t('must_be_positive'));
            document.getElementById('amount')?.focus();
            return;
        }

        setSaving(true);

        try {
            await onSave({ customer, amount, mode, payment_date: date });
            navigate(`/khata/${customer.uuid}`);
        } catch (err) {
            setError(err.message);
            setSaving(false);
        }
    };

    return (
        <Screen title={t('record_payment')} onBack={() => navigate(`/khata/${customer.uuid}`)}>
            <form onSubmit={submit} className="card space-y-4" noValidate>
                <p className="text-sm text-ink-muted">{customer.name}</p>

                <Field id="amount" label={t('amount')} error={error}>
                    <input
                        id="amount"
                        /* decimal keypad, not a spinner — faster and no accidental
                           scroll-to-change on a touch device */
                        inputMode="decimal"
                        className="field-input tabular text-lg"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        autoFocus
                        placeholder="0.00"
                        aria-invalid={error ? 'true' : undefined}
                        aria-describedby={error ? 'amount-error' : undefined}
                        data-testid="payment-amount"
                    />
                </Field>

                <Field id="mode" label={t('mode')}>
                    <select
                        id="mode"
                        className="field-input"
                        value={mode}
                        onChange={(e) => setMode(e.target.value)}
                    >
                        {['cash', 'upi', 'cheque', 'bank', 'other'].map((m) => (
                            <option key={m} value={m}>
                                {t(m)}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field id="date" label={t('date')}>
                    <input
                        id="date"
                        type="date"
                        className="field-input tabular"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                    />
                </Field>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-payment">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}

/* ------------------------------------------------------------------ */

export function RecordSale({ customer, packs, onSave }) {
    const [lines, setLines] = useState([{ product_pack_id: '', qty: '1' }]);
    const [date, setDate] = useState(today());
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    const setLine = (index, key) => (e) =>
        setLines((current) =>
            current.map((line, i) => (i === index ? { ...line, [key]: e.target.value } : line))
        );

    const totalPaise = lines.reduce((sum, line) => {
        const pack = packs.find((p) => p.id === line.product_pack_id);
        const qty = Number(line.qty);

        if (!pack || !Number.isFinite(qty)) return sum;

        // Mirrors LedgerWriter: line_total = rate * qty, in exact paise.
        return sum + toPaise(pack.default_sell_price) * qty;
    }, 0);

    const submit = async (e) => {
        e.preventDefault();

        const valid = lines.filter((l) => l.product_pack_id && Number(l.qty) !== 0);

        // Server rule: at least one line, qty not zero.
        if (valid.length === 0) {
            setError(t('select_product'));
            return;
        }

        setSaving(true);

        try {
            await onSave({
                customer,
                sale_date: date,
                lines: valid.map((l) => ({ product_pack_id: l.product_pack_id, qty: Number(l.qty) })),
                total: (totalPaise / 100).toFixed(2),
            });
            navigate(`/khata/${customer.uuid}`);
        } catch (err) {
            setError(err.message);
            setSaving(false);
        }
    };

    if (packs.length === 0) {
        // The catalog is cached on sync; without it a sale cannot be described.
        return (
            <Screen title={t('record_sale')} onBack={() => navigate(`/khata/${customer.uuid}`)}>
                <p className="card text-center text-warning">{t('no_catalog')}</p>
            </Screen>
        );
    }

    return (
        <Screen title={t('record_sale')} onBack={() => navigate(`/khata/${customer.uuid}`)}>
            <form onSubmit={submit} className="card space-y-4" noValidate>
                <p className="text-sm text-ink-muted">{customer.name}</p>

                {error && (
                    <p role="alert" className="field-error">
                        {error}
                    </p>
                )}

                {lines.map((line, index) => (
                    <div key={index} className="flex gap-2">
                        <div className="min-w-0 flex-1">
                            <label htmlFor={`pack-${index}`} className="field-label">
                                {t('select_product')}
                            </label>
                            <select
                                id={`pack-${index}`}
                                className="field-input"
                                value={line.product_pack_id}
                                onChange={setLine(index, 'product_pack_id')}
                                data-testid={`sale-pack-${index}`}
                            >
                                <option value="">—</option>
                                {packs.map((pack) => (
                                    <option key={pack.id} value={pack.id}>
                                        {pack.label} · {formatRupees(toPaise(pack.default_sell_price))}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="w-20 shrink-0">
                            <label htmlFor={`qty-${index}`} className="field-label">
                                {t('qty')}
                            </label>
                            <input
                                id={`qty-${index}`}
                                inputMode="numeric"
                                className="field-input tabular"
                                value={line.qty}
                                onChange={setLine(index, 'qty')}
                                data-testid={`sale-qty-${index}`}
                            />
                        </div>
                    </div>
                ))}

                <button
                    type="button"
                    className="btn-secondary w-full"
                    onClick={() => setLines((l) => [...l, { product_pack_id: '', qty: '1' }])}
                >
                    + {t('add_line')}
                </button>

                <Field id="sale-date" label={t('date')}>
                    <input
                        id="sale-date"
                        type="date"
                        className="field-input tabular"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                    />
                </Field>

                <div className="flex items-center justify-between border-t border-hairline pt-3">
                    <span className="font-medium">{t('total')}</span>
                    <span className="tabular text-xl font-bold" data-testid="sale-total">
                        {formatRupees(totalPaise)}
                    </span>
                </div>

                <button type="submit" className="btn-primary w-full" disabled={saving} data-testid="save-sale">
                    {saving ? t('loading') : t('save')}
                </button>
            </form>
        </Screen>
    );
}
